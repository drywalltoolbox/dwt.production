import csv, json, re, hashlib, html, os, collections, difflib, datetime
from pathlib import Path
from decimal import Decimal, InvalidOperation
ROOT=Path(__file__).resolve().parents[3]
EXP=ROOT/'columbia-catalog-export'; CAT=ROOT/'products/launch/official/dtb_official_catalog.csv'; OUT=Path(__file__).resolve().parent

def readcsv(p):
 with p.open(encoding='utf-8-sig',newline='') as f:return list(csv.DictReader(f))
def writecsv(name,rows,fields):
 with (OUT/name).open('w',encoding='utf-8-sig',newline='') as f:
  w=csv.DictWriter(f,fieldnames=fields,extrasaction='ignore');w.writeheader();w.writerows(rows)
def key(s):return (s or '').strip().casefold()
def normid(s):return re.sub(r'[^a-z0-9]','',key(s))
def text(s):return re.sub(r'\s+',' ',html.unescape(re.sub(r'<[^>]+>',' ',s or ''))).strip()
def tokens(s):return re.findall(r'[a-z]+|\d+(?:\.\d+)?',key(s))
def num(s):
 try:return Decimal(s)
 except (InvalidOperation,TypeError):return None
def sha(p):
 h=hashlib.sha256()
 with p.open('rb') as f:
  for b in iter(lambda:f.read(1024*1024),b''):h.update(b)
 return h.hexdigest()
def csvsafe(v):
 s='' if v is None else str(v)
 return "'"+s if s.startswith(('=','+','-','@','\t','\r')) else s

def write(name,rows):
 if not rows:return
 rows=[{k:csvsafe(v) for k,v in x.items()} for x in rows];writecsv(name,rows,list(rows[0]))

products=json.loads((EXP/'products.json').read_text(encoding='utf-8-sig')); collections_data=json.loads((EXP/'collections.json').read_text(encoding='utf-8-sig')); summary=json.loads((EXP/'scrape_summary.json').read_text(encoding='utf-8-sig'))
source=readcsv(EXP/'product_variants.csv'); allrows=readcsv(CAT)
brand=lambda x:key(x['Brands']) in ('columbia tools','columbia taping tools') or key(x['Meta: _dtb_brand_key']) in ('columbia-tools','columbia-taping-tools')
dtb=[r for r in allrows if brand(r)]; sell=[r for r in dtb if key(r['Type'])!='variable']; parents=[r for r in dtb if key(r['Type'])=='variable']
product_by_id={str(x['id']):x for x in products}
source_skus=collections.Counter(key(x['sku']) for x in source if key(x['sku']))
index=collections.defaultdict(list); normindex=collections.defaultdict(list)
for i,r in enumerate(sell):
 for field in ['SKU','Meta: _dtb_manufacturer_sku','Meta: _dtb_mpn','Meta: schema_mpn']:
  v=key(r[field]);
  if v and i not in index[v]:index[v].append(i)
  n=normid(v)
  if n and i not in normindex[n]:normindex[n].append(i)
matched=collections.defaultdict(list); sourceout=[]; candidates=[]; findings=[]; source_imgs=set(); image_missing=[]

def finding(priority,kind,sku,source_sku,evidence,recommendation,confidence='high'):
 findings.append(dict(priority=priority,kind=kind,dtb_sku=sku,source_sku=source_sku,evidence=evidence,recommendation=recommendation,confidence=confidence))

for n,s in enumerate(source,1):
 pid=s['spotify_product_id']; p=product_by_id.get(pid,{})
 sv=[v for v in p.get('variants',[]) if key(v.get('sku'))==key(s['sku'])]
 variant=sv[0] if len(sv)==1 else {}; ids=index.get(key(s['sku']),[]) if key(s['sku']) else []
 status='unmatched'; match=None; reason=''
 if len(ids)==1 and source_skus[key(s['sku'])]==1:
  match=ids[0]; status='exact_identifier' if key(s['sku'])==key(sell[match]['SKU']) else 'manufacturer_identifier_review'; matched[match].append(n); reason='Unique case-insensitive SKU match' if status=='exact_identifier' else 'Unique manufacturer identifier match; differing DTB SKU requires identity review'
 elif ids:status='ambiguous_identifier';reason='Duplicate source identifier or multiple DTB identifier targets'
 elif not key(s['sku']):status='missing_source_sku';reason='Manufacturer variant has no SKU'
 if match is None:
  norm=normindex.get(normid(s['sku']),[]) if normid(s['sku']) else []
  scores=[]
  for j,r in enumerate(sell):
   a=tokens(s['product_name']); b=tokens(r['Name']); aset=set(a);bset=set(b)
   seq=difflib.SequenceMatcher(None,' '.join(a),' '.join(b)).ratio(); overlap=len(aset&bset)/max(1,len(aset|bset))
   score=.55*seq+.45*overlap
   if j in norm:score=max(score,.88)
   if score>=.48:scores.append((score,j,seq,overlap))
  for score,j,seq,overlap in sorted(scores,reverse=True)[:3]:
   r=sell[j]; nums1=set(re.findall(r'\d+(?:\.\d+)?',s['product_name']));nums2=set(re.findall(r'\d+(?:\.\d+)?',r['Name']))
   candidates.append(dict(source_row=n,source_sku=s['sku'],source_name=s['product_name'],source_product_id=pid,dtb_sku=r['SKU'],dtb_name=r['Name'],dtb_type=r['Type'],dtb_parent=r['Parent'],score=f'{score:.3f}',basis=('format_normalized_identifier; ' if j in norm else '')+f'title_similarity={seq:.3f}; token_overlap={overlap:.3f}',number_conflict='yes' if nums1 and nums2 and nums1!=nums2 else 'no',decision='review_required'))
  if norm and status=='unmatched':status='normalized_identifier_candidate';reason='Formatting-normalized identifier candidate; identity not confirmed'
 elif match is not None:
  r=sell[match]; ds=r['SKU']; ss=s['sku']; spr=num(s['price']); dp=num(r['Regular price'])
  if spr is not None and dp is not None and spr!=dp:
   finding('P2','price_difference',ds,ss,f"DTB regular={r['Regular price']}; manufacturer price={s['price']}; currency and price basis not established",'Review price basis and currency before any pricing decision','medium')
  if not r['Images'].strip():finding('P1','missing_dtb_image',ds,ss,'DTB image field empty; source image availability is listed in source reconciliation','Investigate the mapped product image and approved media source')
  elif 'placeholder' in r['Images'].lower() or 'coming-soon' in r['Images'].lower():finding('P1','placeholder_dtb_image',ds,ss,'DTB image field references a placeholder','Review source image and replace only after identity and media rights are confirmed')
  if not text(r['Description']):finding('P2','missing_description',ds,ss,'DTB description empty','Review manufacturer description and create accurate customer-facing copy')
  if not r['Meta: _dtb_specs_json'].strip() or r['Meta: _dtb_specs_json'].strip() in ('{}','[]'):finding('P3','missing_structured_specs',ds,ss,'DTB structured specification field empty','Add only verified useful product specifications; do not infer absent measurements','medium')
  if key(ss)!=key(ds):finding('P1','identifier_mapping',ds,ss,f"Source SKU matches a DTB manufacturer identifier but differs from DTB SKU {ds}",'Verify identity and preserve both protected identifiers','medium')
 files=[q.strip() for q in s['image_files'].split('|') if q.strip()];source_imgs.update(files)
 for path in files:
  if not (EXP/path).is_file():image_missing.append((n,path))
 sourceout.append(dict(source_row=n,source_product_id=pid,source_variant_id=variant.get('id',''),source_sku=s['sku'],source_name=s['product_name'],source_type=s['product_type'],source_collections=s['collections'],source_price=s['price'],source_available=variant.get('available','unknown'),source_handle=s['handle'],source_url='https://shopcolumbiatools.com/products/'+s['handle'],source_image_files=s['image_files'],source_image_missing_count=sum(not (EXP/q).is_file() for q in files),match_status=status,match_basis=reason,dtb_sku=sell[match]['SKU'] if match is not None else '',dtb_name=sell[match]['Name'] if match is not None else '',dtb_parent=sell[match]['Parent'] if match is not None else '',dtb_type=sell[match]['Type'] if match is not None else '',dtb_regular_price=sell[match]['Regular price'] if match is not None else ''))
for i,rows in matched.items():
 if len(rows)>1:
  for n in rows:sourceout[n-1]['match_status']='ambiguous_many_to_one';sourceout[n-1]['match_basis']='Multiple source variants matched same DTB row; manual review required'
  finding('P1','many_to_one',sell[i]['SKU'],'',f'Source rows {rows} matched this DTB item','Review competing identifiers and product identity')

dtbout=[]
for r in dtb:
 isparent=key(r['Type'])=='variable';i=next((j for j,x in enumerate(sell) if x is r),None); ns=matched.get(i,[]) if i is not None else []
 dtbout.append(dict(dtb_sku=r['SKU'],dtb_name=r['Name'],dtb_type=r['Type'],dtb_parent=r['Parent'],brand=r['Brands'],brand_key=r['Meta: _dtb_brand_key'],published=r['Published'],dtb_price=r['Regular price'],categories=r['Categories'],dtb_images=r['Images'],manufacturer_sku=r['Meta: _dtb_manufacturer_sku'],mpn=r['Meta: _dtb_mpn'],match_status='family_parent_not_compared_as_sellable' if isparent else ('ambiguous_many_to_one' if len(ns)>1 else ('exact_identifier' if sourceout[ns[0]-1]['match_status']=='exact_identifier' else 'manufacturer_identifier_review') if ns else 'not_exact_matched'),source_rows=' | '.join(map(str,ns)),source_skus=' | '.join(source[n-1]['sku'] for n in ns)))
 if not isparent and not ns:finding('P2','dtb_unmatched',r['SKU'],'','No unique exact identifier match in manufacturer snapshot','Investigate legacy SKU, variant naming, and manufacturer identifiers before any catalog removal','medium')
for s in sourceout:
 if s['match_status'] not in ('exact_identifier','manufacturer_identifier_review'):finding('P2','source_unresolved','',s['source_sku'],f"Source row {s['source_row']}: {s['source_name']}; {s['match_status']}",'Review candidate matches and assortment relevance before proposing addition','medium')

jsonrefs=[im.get('local_path','') for p in products for im in p.get('images',[])]; localfiles={str(p.relative_to(EXP)).replace('\\','/') for p in (EXP/'images').rglob('*') if p.is_file()}
source_ids=[str(p['id']) for p in products]; csv_ids=collections.Counter(s['spotify_product_id'] for s in source)
jsonvars=[(str(p['id']),key(v.get('sku'))) for p in products for v in p.get('variants',[])]; csvvars=[(s['spotify_product_id'],key(s['sku'])) for s in source]
collection_counts={c['handle']:sum(any(x.get('handle')==c['handle'] for x in p.get('collections',[])) for p in products) for c in collections_data}
counts={'source_products':len(products),'source_variants_csv':len(source),'source_variants_json':len(jsonvars),'dtb_columbia_rows':len(dtb),'dtb_columbia_sellable_rows':len(sell),'dtb_columbia_parent_rows':len(parents),'source_match_status':dict(collections.Counter(x['match_status'] for x in sourceout)),'dtb_match_status':dict(collections.Counter(x['match_status'] for x in dtbout)),'source_blank_skus':sum(not key(x['sku']) for x in source),'duplicate_source_skus':{k:v for k,v in source_skus.items() if v>1},'duplicate_product_ids':{k:v for k,v in collections.Counter(source_ids).items() if v>1},'variant_multiset_mismatches':sum((collections.Counter(jsonvars)-collections.Counter(csvvars)).values())+sum((collections.Counter(csvvars)-collections.Counter(jsonvars)).values()),'json_image_refs':len(jsonrefs),'json_image_paths_missing':sum(not (EXP/x).is_file() for x in jsonrefs),'csv_unique_image_paths':len(source_imgs),'csv_missing_image_refs':len(image_missing),'image_files_on_disk':len(localfiles),'image_files_unreferenced_by_csv':len(localfiles-source_imgs),'image_refs_missing_from_disk':len(source_imgs-localfiles),'collections_mismatches':{c['handle']:{'reported':c.get('products_count'),'calculated':collection_counts[c['handle']]} for c in collections_data if c.get('products_count')!=collection_counts[c['handle']]},'findings':dict(collections.Counter(x['kind'] for x in findings)),'candidate_rows':len(candidates)}
write('source-reconciliation.csv',sourceout);write('dtb-reconciliation.csv',dtbout);write('match-candidates.csv',candidates);write('findings.csv',findings)
(OUT/'audit-summary.json').write_text(json.dumps({'source_generated_at':summary.get('generated_at'),'hashes':{str(p.relative_to(ROOT)).replace('\\','/'):sha(p) for p in [CAT,EXP/'products.json',EXP/'product_variants.csv',EXP/'collections.json',EXP/'scrape_summary.json']},'counts':counts},indent=2)+'\n',encoding='utf8')
print(json.dumps(counts,indent=2))
