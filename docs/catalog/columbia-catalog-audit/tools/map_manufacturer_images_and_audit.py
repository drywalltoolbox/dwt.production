from pathlib import Path
import csv,json,collections,hashlib,os,tempfile,re
from decimal import Decimal,ROUND_HALF_UP
from urllib.parse import quote
R=Path(__file__).resolve().parents[4];O=Path(__file__).resolve().parent.parent;D=O/'temporary-reference';EXP=R/'columbia-catalog-export';P=R/'products/launch/official/dtb_official_catalog.csv';BASE='https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/'
SOURCE=D/'columbia-manufacturer-woocommerce-reference.csv';WORK=D/'columbia-manufacturer-first-WORKING-NOT-FOR-IMPORT.csv';TRACE=D/'columbia-manufacturer-first-provenance.csv';VAR=EXP/'product_variants.csv';JSON=EXP/'products.json'
def h(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def read(p):
 with p.open(encoding='utf-8-sig',newline='') as f:r=csv.DictReader(f);return r.fieldnames,list(r)
def output(p,fields,rows):
 with tempfile.NamedTemporaryFile(mode='w',encoding='utf-8-sig',newline='',dir=p.parent,prefix='.columbia-media-',suffix='.tmp',delete=False) as f:
  w=csv.DictWriter(f,fieldnames=fields,lineterminator='\r\n',extrasaction='raise');w.writeheader();w.writerows(rows);f.flush();os.fsync(f.fileno());return Path(f.name)
oldhash={p:h(p) for p in [P,SOURCE,WORK,TRACE,VAR,JSON]};fields,base=read(SOURCE);fields2,work=read(WORK);_,trace=read(TRACE);_,allvars=read(VAR);products=json.loads(JSON.read_text(encoding='utf-8-sig'));byid={str(x['id']):x for x in products};assert fields==fields2 and len(base)==len(work)==len(trace)==1006
manifest=[];audit=[];mapped_rows=0;no_source_rows=0;nonmedia_changed=[];owned_paths=collections.defaultdict(set)
for i,(b,w,t) in enumerate(zip(base,work,trace),1):
 idx=int(t['source_row'])-1;s=allvars[idx];pid=s['spotify_product_id'];p=byid[pid];variants=[v for v in p['variants'] if str(v['id'])==t['source_variant_id']]
 if len(variants)!=1:raise ValueError('variant missing/duplicate '+pid)
 v=variants[0]
 if p['product_type'] in ('Merchandise','Parts Kit') or re.search(r'\bpack(?:s)?\b',p['title'],re.I) or b['SKU']!=s['sku'] or w['SKU']!=s['sku'] or t['source_sku']!=s['sku'] or p['title']!=s['product_name'] or b['Name']!=p['title'] or w['Name']!=p['title'] or re.sub(r'\s+',' ',p['description_html']).strip()!=re.sub(r'\s+',' ',s['description_html']).strip() or str(v['price'])!=s['price']:raise ValueError('source or product mismatch '+str(i)+' '+pid)
 b['Description']=p['description_html'];w['Description']=p['description_html']
 paths=[x.strip() for x in s['image_files'].split('|') if x.strip()];source_images=p['images']
 if len(paths)!=len(source_images):raise ValueError('image count mismatch '+pid)
 urls=[]
 for index,(path,img) in enumerate(zip(paths,source_images),1):
  local=(EXP/path).resolve();root=(EXP/'images').resolve()
  if local.parent!=root or not local.is_file():raise ValueError('image missing or outside images directory '+path)
  if local.suffix.casefold()!='.webp':raise ValueError('unexpected media extension '+path)
  owned_paths[path].add(pid);url=BASE+quote(local.name,safe='-._~');urls.append(url)
  manifest.append(dict(source_row=t['source_row'],source_product_id=pid,source_variant_id=t['source_variant_id'],source_sku=s['sku'],source_title=p['title'],source_product_url=t['source_url'],position=index,local_path=path,local_bytes=local.stat().st_size,target_url=url,manufacturer_image_url=img.get('src',''),manufacturer_image_id=img.get('id',''),association='source CSV product-image list and JSON image order; local file exists',public_reachability='not individually verified'))
 previous=w['Images'];
 if urls:
  mapped_rows+=1;b['Images']=', '.join(urls);w['Images']=', '.join(urls)
 else:
  no_source_rows+=1;b['Images']='';w['Images']=''
 imgs_inherit=w['Meta: _dtb_inherit_parent_image'];flags=[]
 if w['Type']=='variation' and imgs_inherit=='1':
  w['Meta: _dtb_inherit_parent_image']='0';flags.append('reference_inheritance_disabled_check_parent_fallback')
 if not urls:flags.append('no_manufacturer_image_in_export')
 if w['Type']=='variation' and imgs_inherit=='1' and urls:flags.append('review_parent_image_inheritance_with_mapped_variant_images')
 if t['disposition']!='exact_existing_match':flags.append('identity_and_category_review_required')
 audit.append(dict(source_row=t['source_row'],source_product_id=pid,source_variant_id=t['source_variant_id'],source_sku=s['sku'],source_title=p['title'],source_handle=p['handle'],source_product_url=t['source_url'],source_vendor=p['vendor'],source_product_type=p['product_type'],source_tags=json.dumps(p['tags'],ensure_ascii=False),source_collections=json.dumps(p['collections'],ensure_ascii=False),source_options=json.dumps(p['options'],ensure_ascii=False),source_variant_title=v.get('title',''),source_variant_available=v.get('available',''),source_variant_grams=v.get('grams',''),source_variant_price=v['price'],source_variant_compare_at_price=v.get('compare_at_price',''),source_description_html=p['description_html'],source_description_text=p['description_text'],source_image_count=len(source_images),source_image_files=' | '.join(paths),mapped_target_urls=' | '.join(urls),previous_working_images=previous,working_type=w['Type'],working_parent=w['Parent'],existing_inherit_parent_image=imgs_inherit,disposition=t['disposition'],review_flags=' | '.join(flags)))
if any(len(v)>1 for v in owned_paths.values()):raise ValueError('local image filename reused across different source product IDs')
# Verify source price + requested calculation and no mutation of non-image fields.
for row,s,t in zip(work,base,trace):
 p=Decimal(s['Sale price']);assert row['Sale price']==format(p,'.2f') and row['Regular price']==format((p*Decimal('1.10')).quantize(Decimal('.01'),rounding=ROUND_HALF_UP),'.2f')
for p in oldhash:
 if h(p)!=oldhash[p]:raise RuntimeError('Input changed while preparing: '+str(p))
temps=[]
try:
 for target,cols,rows in [(SOURCE,fields,base),(WORK,fields,work),(O/'evidence/manufacturer/columbia-manufacturer-image-manifest.csv',list(manifest[0]),manifest),(O/'evidence/manufacturer/columbia-products-json-audit.csv',list(audit[0]),audit)]:temps.append((target,output(target,cols,rows)))
 for target,tmp in temps:os.replace(tmp,target)
finally:
 for _,tmp in temps:tmp.unlink(missing_ok=True)
assert h(P)==oldhash[P]
for p,expected in [(SOURCE,base),(WORK,work)]:
 ff,actual=read(p);assert ff==fields and actual==expected
print(json.dumps({'source_products_audited':len(audit),'products_with_export_images':mapped_rows,'products_without_export_images':no_source_rows,'image_mappings':len(manifest),'unique_local_images':len(owned_paths),'variation_parent_inherit_flags':sum('review_parent_image_inheritance' in x['review_flags'] for x in audit),'official_catalog_unchanged':h(P),'baseline_image_csv_sha256':h(SOURCE),'working_csv_sha256':h(WORK)},indent=2))
