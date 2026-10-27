from __future__ import annotations
import argparse,csv,json,collections,hashlib,re,os,time,concurrent.futures,threading
from pathlib import Path
from urllib.parse import unquote,urlsplit,quote
from PIL import Image
import requests
R=Path(__file__).resolve().parents[4];O=R/'docs/catalog/columbia-catalog-audit';E=O/'evidence/manufacturer';CP=O/'current-checkpoint/dtb_columbia_catalog_official.csv';TRACE=O/'temporary-reference/columbia-manufacturer-first-provenance.csv';DISP=O/'review/manufacturer-rebuild-dispositions.csv';TSW=O/'evidence/tsw/tsw-manufacturer-shipping-audit.csv';ALT=O/'archive/previous-audits/fuzzy-price-changes.csv';CAT=R/'products/launch/official/dtb_official_catalog.csv';VAR=R/'columbia-catalog-export/product_variants.csv';PROD=R/'columbia-catalog-export/products.json';HIST=R/'products/launch/media/audit/catalog-image-mapping-manifest.csv';DIRS=[R/'products/launch/media/media',R/'columbia-catalog-export/images'];BASE='https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/'
csv.field_size_limit(10000000)
def read(p):
 with p.open(encoding='utf-8-sig',newline='') as f:return list(csv.DictReader(f))
def h(p):
 result=hashlib.sha256()
 with p.open('rb') as f:
  for block in iter(lambda:f.read(1024*1024),b''):result.update(block)
 return result.hexdigest()
def output(path,rows,cols=None):
 if cols is None:cols=list(rows[0])
 with path.open('w',encoding='utf-8-sig',newline='') as f:w=csv.DictWriter(f,fieldnames=cols,lineterminator='\r\n');w.writeheader();w.writerows(rows)
def normalized(s):return re.sub(r'[^a-z0-9]','',s.casefold())
def slug(s):return re.sub(r'[^a-z0-9]+','_',s.casefold()).strip('_')
def image_urls(value):return [x.strip() for x in value.split(',') if x.strip()]
def basename(url):return unquote(Path(urlsplit(url).path).name)
inputs=[CP,TRACE,DISP,TSW,ALT,CAT,VAR,PROD,HIST];before={str(p.relative_to(R)).replace('\\','/'):h(p) for p in inputs}
w=read(CP);trace=read(TRACE);disp=read(DISP);official=read(CAT);v=read(VAR);products={str(x['id']):x for x in json.loads(PROD.read_text(encoding='utf8'))};shipping=read(TSW);fuzzy=read(ALT)
if len(w)!=len(trace) or len(w)!=len(disp) or any(a['SKU']!=b['source_sku'] or a['SKU']!=c['source_sku'] for a,b,c in zip(w,trace,disp)):raise ValueError('Checkpoint/source alignment mismatch')
historic={(x['sku'].strip().casefold(),x['filename'].strip().casefold()) for x in read(HIST) if x['type'] in ('simple','variation')}
old=collections.defaultdict(list)
for x in official:
 if x['Brands'].strip().casefold() in ('columbia tools','columbia taping tools'):old[x['SKU'].strip().casefold()].append(x)
alt={x['source_row']:x for x in fuzzy};ship={x['manufacturer_sku']:x for x in shipping};owners=collections.defaultdict(set)
for x in v:
 for path in x['image_files'].split('|'):
  if path.strip():owners[Path(path.strip()).name.casefold()].add(x['spotify_product_id'])
files=collections.defaultdict(list);filename_by_sku=collections.defaultdict(list);key_owners=collections.defaultdict(set)
for x in w:key_owners[normalized(x['SKU'])].add(x['SKU'])
for x in official:
 if x['Brands'].strip().casefold() in ('columbia tools','columbia taping tools'):key_owners[normalized(x['SKU'])].add(x['SKU'])
for directory in DIRS:
 if not directory.is_dir():raise FileNotFoundError(directory)
 for file in directory.rglob('*'):
  if not file.is_file():continue
  files[file.name.casefold()].append(file)
  match=re.fullmatch(r'columbia_tools_(.+?)_(\d+)(?:-scaled)?\.webp',file.name.casefold())
  if match:filename_by_sku[match.group(1)].append(file.name)
placeholder=DIRS[0]/'Photo-Coming-Soon-Placeholder.webp';placeholder_hash=h(placeholder) if placeholder.is_file() else ''
products_out=[];images_out=[];cache={};
for row,t,d in zip(w,trace,disp):
 if row['Images'].strip():continue
 s=v[int(t['source_row'])-1];pid=t['source_product_id'];p=products[pid]
 if s['spotify_product_id']!=pid or s['sku']!=row['SKU'] or p['title']!=row['Name']:raise ValueError('Source identity mismatch '+row['SKU'])
 source_paths=[a.strip() for a in s['image_files'].split('|') if a.strip()];candidates={};flags=[];identity_notes=[];oldrow=None;sku=row['SKU'];source_id=pid
 rec=ship.get(sku,{})
 if rec.get('review_reasons') and any(q in rec['review_reasons'].lower() for q in ['identity','description','disagreement','conflict','pack','normalized_identifier','duplicate']):identity_notes.append(rec['review_reasons'])
 if d['disposition'] in ('unresolved_identifier_conflict','unresolved_catalog_sku_collision','unresolved_source_identity'):identity_notes.append(d['disposition'])
 if len(key_owners[normalized(sku)])>1:identity_notes.append('punctuation_normalization_collides_with_other_catalog_skus')
 def add(name,evidence,official_url='',alias=False):
  key=name.casefold();locations=files.get(key,[])
  if not locations:return
  item=candidates.setdefault(key,{'basename':locations[0].name,'locations':locations,'evidence':set(),'official_urls':set(),'alias':False})
  item['evidence'].add(evidence)
  if official_url:item['official_urls'].add(official_url)
  item['alias']|=alias
 # Manufacturer export mapping is product-ID scoped; absent local files are not inferred.
 for item in source_paths:add(Path(item).name,'manufacturer_product_id_image_list')
 hits=old.get(sku.casefold(),[])
 if len(hits)==1 and hits[0]['Type'] in ('simple','variation'):
  oldrow=hits[0]
  if d['disposition']!='exact_existing_match':identity_notes.append('existing_sku_link_not_confirmed_by_prior_identity_audit')
 elif len(hits)==1 and hits[0]['Type']=='variable':flags.append('same_sku_is_existing_variable_parent_not_sellable_product')
 elif len(hits)>1:identity_notes.append('multiple_existing_catalog_skus')
 if oldrow is None and t['source_row'] in alt:
  alias=alt[t['source_row']];target=old.get(alias['dtb_sku'].casefold(),[])
  if len(target)==1 and target[0]['Type'] in ('simple','variation'):
   oldrow=target[0];identity_notes.append('alternate_sku_requires_verification')
 if oldrow:
  for u in image_urls(oldrow['Images']):
   if any(q in u.casefold() for q in ('placeholder','coming-soon')):flags.append('existing_dtb_image_is_placeholder');continue
   name=basename(u);add(name,'current_dtb_catalog_image_url',u)
   if name.casefold().endswith('-scaled.webp') and not files.get(name.casefold()):add(name[:-12]+'.webp','unscaled_basename_candidate',u,True)
 # Filename matching is only a lead, never proof of product identity.
 for name in sorted(set(filename_by_sku.get(slug(sku),[])),key=str.casefold):add(name,'sku_derived_filename_review')
 for item in candidates.values():
  key=item['basename'].casefold();locs=item['locations'];imgmeta=[];hashes=[]
  for loc in locs:
   k=str(loc)
   if k not in cache:
    try:
     with Image.open(loc) as im:size=f'{im.width}x{im.height}';fmt=im.format;im.verify()
     cache[k]=(h(loc),size,fmt,'valid')
    except Exception as e:cache[k]=('', '', '', 'invalid:'+type(e).__name__)
   sha,size,fmt,valid=cache[k];hashes.append(sha);imgmeta.append((str(loc.relative_to(R)).replace('\\','/'),sha,size,fmt,valid))
  assetflags=[]
  if any(x[4]!='valid' for x in imgmeta):assetflags.append('invalid_image_file')
  if len(set(x for x in hashes if x))>1:assetflags.append('same_basename_has_different_file_contents')
  if placeholder_hash and placeholder_hash in hashes:assetflags.append('same_hash_as_placeholder')
  sourceowners=owners.get(key,set())
  if sourceowners and source_id not in sourceowners:assetflags.append('manufacturer_export_assigns_filename_to_different_product')
  if item['alias']:assetflags.append('unscaled_file_is_not_same_filename_as_dtb_url')
  fileparts=re.fullmatch(r'columbia_tools_(.+?)_(\d+)(?:-scaled)?\.webp',key)
  valid_stems={slug(sku)}
  if oldrow:valid_stems.add(slug(oldrow['SKU']))
  if fileparts and fileparts.group(1) not in valid_stems:assetflags.append('filename_contains_different_sku')
  if oldrow and (oldrow['SKU'].strip().casefold(),key) in historic:item['evidence'].add('historical_dtb_gallery_manifest')
  if identity_notes:assetflags.append('product_identity_review')
  if 'manufacturer_product_id_image_list' in item['evidence'] and not assetflags:confidence='manufacturer_product_link'
  elif 'current_dtb_catalog_image_url' in item['evidence'] and not assetflags and d['disposition']=='exact_existing_match':confidence='existing_dtb_sku_link_review_visual'
  else:confidence='candidate_requires_review'
  images_out.append(dict(source_row=t['source_row'],source_product_id=pid,manufacturer_sku=sku,manufacturer_name=p['title'],disposition=d['disposition'],dtb_sku=oldrow['SKU'] if oldrow else '',image_basename=item['basename'],intended_media_url=BASE+quote(item['basename'],safe='-._~'),current_dtb_urls=' | '.join(sorted(item['official_urls'])),local_paths=' | '.join(z[0] for z in imgmeta),image_hashes=' | '.join(z[1] for z in imgmeta),image_dimensions=' | '.join(z[2] for z in imgmeta),evidence=' | '.join(sorted(item['evidence'])),assessment=confidence,review_flags=' | '.join(assetflags),public_http_status='not_checked',public_content_type='',public_check_note=''))
 scoped=[x for x in images_out if x['source_row']==t['source_row']];counts=collections.Counter(x['assessment'] for x in scoped)
 if counts['manufacturer_product_link']:state='manufacturer_linked_local_image'
 elif counts['existing_dtb_sku_link_review_visual']:state='current_dtb_linked_local_candidate'
 elif scoped:state='candidate_requires_review'
 else:state='no_local_candidate'
 if source_paths and not any('manufacturer_product_id_image_list' in x['evidence'] for x in scoped):flags.append('source_image_file_missing_from_both_directories')
 products_out.append(dict(source_row=t['source_row'],source_product_id=pid,source_variant_id=t['source_variant_id'],manufacturer_sku=sku,manufacturer_name=p['title'],source_url=t['source_url'],manufacturer_type=p['product_type'],checkpoint_type=row['Type'],checkpoint_parent=row['Parent'],match_disposition=d['disposition'],existing_dtb_sku=oldrow['SKU'] if oldrow else '',existing_dtb_type=oldrow['Type'] if oldrow else '',existing_dtb_images=oldrow['Images'] if oldrow else '',manufacturer_export_image_paths=' | '.join(source_paths),candidate_count=len(scoped),product_assessment=state,review_flags=' | '.join(dict.fromkeys(flags+identity_notes))))
# Cross-product duplicate image content is a review signal, not proof of incorrect imagery.
hashowners=collections.defaultdict(set)
for x in images_out:
 for sha in x['image_hashes'].split(' | '):
  if sha:hashowners[sha].add(x['source_product_id'])
for x in images_out:
 if any(len(hashowners[s])>1 for s in x['image_hashes'].split(' | ') if s):
  x['review_flags']=(x['review_flags']+' | ' if x['review_flags'] else '')+'identical_file_content_used_by_multiple_products'
  x['assessment']='candidate_requires_review'
# Reconcile product dispositions after cross-product hash analysis.
for product in products_out:
 scoped=[x for x in images_out if x['source_row']==product['source_row']]
 counts=collections.Counter(x['assessment'] for x in scoped)
 if counts['manufacturer_product_link']:product['product_assessment']='manufacturer_linked_local_image'
 elif counts['existing_dtb_sku_link_review_visual']:product['product_assessment']='current_dtb_linked_local_candidate'
 elif scoped:product['product_assessment']='candidate_requires_review'
 else:product['product_assessment']='no_local_candidate'
for path,expected in before.items():
 if h(R/path)!=expected:raise RuntimeError('Input changed during audit: '+path)
E.mkdir(parents=True,exist_ok=True);output(E/'empty-gallery-products.csv',products_out);output(E/'empty-gallery-image-candidates.csv',images_out)
summary={'input_sha256':before,'checkpoint_product_rows':len(w),'empty_gallery_products':len(products_out),'product_assessment':dict(collections.Counter(x['product_assessment'] for x in products_out)),'image_candidates':len(images_out),'image_assessment':dict(collections.Counter(x['assessment'] for x in images_out)),'image_review_flags':dict(collections.Counter(z.strip() for x in images_out for z in x['review_flags'].split('|') if z.strip())),'local_directory_file_counts':{str(p.relative_to(R)).replace('\\','/'):sum(1 for f in p.rglob('*') if f.is_file()) for p in DIRS},'public_checks':'not_run','official_catalog_changed':False,'checkpoint_changed':False}
(E/'empty-gallery-audit-summary.json').write_text(json.dumps(summary,indent=2)+'\n',encoding='utf8');print(json.dumps(summary,indent=2))
