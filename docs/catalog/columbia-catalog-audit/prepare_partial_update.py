from pathlib import Path
import csv,json,hashlib,io,shutil,os,tempfile,datetime
R=Path(__file__).resolve().parents[3];O=Path(__file__).resolve().parent;C=R/'products/launch/official/dtb_official_catalog.csv';E=R/'columbia-catalog-export'
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
audit=json.loads((O/'audit-summary.json').read_text(encoding='utf8'));base=audit['hashes'][str(C.relative_to(R)).replace('\\','/')]
if sha(C)!=base:raise SystemExit('Official catalog changed since audit; refusing write')
with C.open(encoding='utf-8-sig',newline='') as f:q=csv.DictReader(f);fields=q.fieldnames;rows=list(q)
with (O/'columbia-catalog-proposed.csv').open(encoding='utf-8-sig',newline='') as f:proposal={r['SKU']:r for r in csv.DictReader(f)}
with (O/'source-reconciliation.csv').open(encoding='utf-8-sig',newline='') as f:matches=[r for r in csv.DictReader(f) if r['match_status']=='exact_identifier']
prices=['Regular price','Sale price','Date sale price starts','Date sale price ends'];identity=['Meta: schema_mpn','Meta: _dtb_manufacturer_sku','Meta: _dtb_mpn'];corrected={'FA380','FA379','FA299','FA279','FA294','CT109','FA225','FA222'}; changes=[]
for match in matches:
 sku=match['dtb_sku'];r=next(x for x in rows if x['SKU']==sku);p=proposal[sku];allowed=prices+identity
 if sku in corrected:allowed+=['Name','Short description','Description']
 if sku=='FA279':allowed+=['Meta: _dtb_specs_json']
 for field in allowed:
  if r[field]!=p[field]:changes.append(dict(sku=sku,field=field,old_value=r[field],new_value=p[field],evidence='Columbia export exact SKU; prices USD confirmed by user'));r[field]=p[field]
 if sku=='FA222':
  specs=json.loads(r['Meta: _dtb_specs_json'])
  for spec in specs:
   if spec.get('label','').strip().lower()=='length':spec['value']='7/16 inch'
  value=json.dumps(specs,ensure_ascii=False,separators=(',',':'));field='Meta: _dtb_specs_json'
  if r[field]!=value:changes.append(dict(sku=sku,field=field,old_value=r[field],new_value=value,evidence='Columbia FA222 title specifies 7/16'));r[field]=value
 if sku in corrected:
  title=proposal[sku]['Name'];seo={'Meta: _dtb_seo_title':f'Columbia {title} | {sku}','Meta: _dtb_seo_description':f'Columbia {title}. Manufacturer part number {sku}.','Meta: _dtb_seo_focus_kw':f'Columbia {sku}'}
  for field,value in seo.items():
   if r[field]!=value:changes.append(dict(sku=sku,field=field,old_value=r[field],new_value=value,evidence='Columbia source product title and exact SKU'));r[field]=value
out=O/'partial-catalog-update.csv'
with out.open('w',encoding='utf-8-sig',newline='') as f:w=csv.DictWriter(f,fieldnames=fields,lineterminator='\r\n');w.writeheader();w.writerows(rows)
with (O/'applied-changes.csv').open('w',encoding='utf-8-sig',newline='') as f:w=csv.DictWriter(f,fieldnames=changes[0].keys());w.writeheader();w.writerows(changes)
print('preview',len(changes),'matched',len(matches),'original',base,'candidate',sha(out));print('VALIDATE PREVIEW BEFORE APPLY')
