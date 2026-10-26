from pathlib import Path
import csv,json,hashlib,io,re,html,collections
import bleach
R=Path(__file__).resolve().parents[3]; O=Path(__file__).resolve().parent; C=R/'products/launch/official/dtb_official_catalog.csv'; E=R/'columbia-catalog-export'
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
audit=json.loads((O/'audit-summary.json').read_text(encoding='utf-8'))
for name,hashvalue in audit['hashes'].items():
 if sha(R/name)!=hashvalue:raise SystemExit('Source differs from audit snapshot: '+name)
with C.open(encoding='utf-8-sig',newline='') as f:reader=csv.DictReader(f);fields=reader.fieldnames;rows=list(reader)
with (O/'source-reconciliation.csv').open(encoding='utf-8-sig',newline='') as f:matched=[x for x in csv.DictReader(f) if x['match_status']=='exact_identifier']
products={str(x['id']):x for x in json.loads((E/'products.json').read_text(encoding='utf-8'))}; bysku={r['SKU']:r for r in rows};changes=[];review=[]
allowed={'p','h2','h3','h4','ul','ol','li','strong','em','br'}
def add(r,field,value,source):
 old=r[field]
 if old!=value:
  changes.append({'sku':r['SKU'],'field':field,'old_value':old,'new_value':value,'source':source});r[field]=value
for match in matched:
 r=bysku[match['dtb_sku']];p=products[match['source_product_id']];sku=match['source_sku']; variants=[v for v in p['variants'] if (v.get('sku') or '').strip().casefold()==sku.strip().casefold()]
 if len(variants)!=1:raise SystemExit('Variant ambiguity: '+sku)
 v=variants[0];source='manufacturer product '+str(p['id'])+' variant '+str(v['id'])
 price=v.get('price');
 if not re.fullmatch(r'\d+(?:\.\d{1,2})?',str(price)):raise SystemExit('Invalid price: '+sku)
 add(r,'Regular price',str(price),source+'; USD confirmed by user')
 # User requested source prices: an inherited DTB sale would otherwise override them.
 for field in ['Sale price','Date sale price starts','Date sale price ends']:add(r,field,'',source+'; source has no compare-at price')
 # Exact supplier SKU only; conflicting preexisting manufacturer IDs are isolated for review.
 idfields=['Meta: schema_mpn','Meta: _dtb_manufacturer_sku','Meta: _dtb_mpn']
 ids=[r[f].strip() for f in idfields if r[f].strip()]
 if ids and any(x.casefold()!=sku.strip().casefold() for x in ids):review.append({'sku':sku,'issue':'identifier_field_conflict','source':sku,'current':repr(ids),'action':'Review identity; preserve existing identifier fields'})
 else:
  for f in idfields:add(r,f,sku,source)
 # The manufacturer's complete product title and description are the requested source for this exact SKU.
 title=p['title'].strip();description=bleach.clean(p['description_html'],tags=allowed,attributes={},strip=True).strip()
 if not title or not description:raise SystemExit('Missing title or description: '+sku)
 add(r,'Name',title,source);add(r,'Short description',html.escape(title,quote=False),source);add(r,'Description',description,source)
 # Existing specs and structured features may include independent facts absent from source; flag, do not invent replacements.
 review.append({'sku':sku,'issue':'specifications_and_metadata_review','source':title,'current':r['Meta: _dtb_specs_json'],'action':'Reconcile each existing specification and structured feature against manufacturer evidence; absent source evidence is unknown'})
# The manufacturer explicitly specifies FA279 as 1 inch; the previous catalog stated 1-1/4 inches.
r=bysku['FA279'];specs=json.loads(r['Meta: _dtb_specs_json']);found=False
for x in specs:
 if x.get('label','').strip().casefold()=='length':x['value']='1 in';found=True
if not found:specs.append({'label':'Length','value':'1 in'})
add(r,'Meta: _dtb_specs_json',json.dumps(specs,ensure_ascii=False,separators=(',',':')),'Manufacturer FA279 title: 10-24 X 1 inch Fillister Slot')
with (O/'columbia-catalog-proposed.csv').open('w',encoding='utf-8-sig',newline='') as f:
 w=csv.DictWriter(f,fieldnames=fields,lineterminator='\r\n');w.writeheader();w.writerows(rows)
for name,data in [('proposed-changes.csv',changes),('metadata-review.csv',review)]:
 with (O/name).open('w',encoding='utf-8-sig',newline='') as f:
  w=csv.DictWriter(f,fieldnames=data[0].keys());w.writeheader();w.writerows(data)
print('matched',len(matched),'change fields',len(changes),'field counts',dict(collections.Counter(x['field'] for x in changes)),'review',len(review),'proposed hash',sha(O/'columbia-catalog-proposed.csv'))
