import csv, json, re, html, collections
from pathlib import Path
from decimal import Decimal,InvalidOperation
from PIL import Image
R=Path(__file__).resolve().parents[3]; O=Path(__file__).resolve().parent; E=R/'columbia-catalog-export'
def read(p):
 with p.open(encoding='utf-8-sig',newline='') as f:return list(csv.DictReader(f))
def plain(s):return re.sub(r'\s+',' ',html.unescape(re.sub(r'<[^>]*>',' ',s or ''))).strip()
def num(s):
 try:return Decimal(s)
 except (InvalidOperation,TypeError):return None
def safe(v):
 s='' if v is None else str(v);return "'"+s if s.startswith(('=','+','-','@','\t','\r')) else s
def out(name,rows):
 if not rows:return
 with (O/name).open('w',encoding='utf-8-sig',newline='') as f:
  w=csv.DictWriter(f,fieldnames=list(rows[0]));w.writeheader();w.writerows([{k:safe(v) for k,v in x.items()} for x in rows])
src=read(O/'source-reconciliation.csv'); catalog=read(R/'products/launch/official/dtb_official_catalog.csv'); csvsrc=read(E/'product_variants.csv'); products=json.loads((E/'products.json').read_text(encoding='utf-8-sig')); byid={str(p['id']):p for p in products}; bysku={p['SKU']:p for p in catalog}
comparisons=[]; integrity=[]
for x in src:
 n=int(x['source_row']);s=csvsrc[n-1];p=byid.get(x['source_product_id'],{});vs=[v for v in p.get('variants',[]) if (v.get('sku') or '').strip().casefold()==s['sku'].strip().casefold()]
 if not p:integrity.append(dict(source_row=n,kind='unknown_product_id',detail=x['source_product_id']))
 elif p.get('title')!=s['product_name'] or p.get('handle')!=s['handle'] or p.get('product_type')!=s['product_type']:integrity.append(dict(source_row=n,kind='product_metadata_disagreement',detail='CSV name, handle, or product type differs from JSON'))
 if len(vs)==1 and str(vs[0].get('price'))!=s['price']:integrity.append(dict(source_row=n,kind='price_disagreement',detail=f"CSV {s['price']} vs JSON {vs[0].get('price')}"))
 if x['match_status'] not in ('exact_identifier','manufacturer_identifier_review'):continue
 r=bysku[x['dtb_sku']]; source_desc=plain(p.get('description_text') or p.get('description_html','')); dtb_desc=plain(r['Description']);source_price=num(x['source_price']);dtb_price=num(r['Regular price']);images=x['source_image_files']; image_state='placeholder' if any(t in r['Images'].lower() for t in ('placeholder','coming-soon')) else 'populated' if r['Images'].strip() else 'empty'; parent=bysku.get(r['Parent'],{})
 comparisons.append(dict(source_row=n,source_product_id=x['source_product_id'],source_sku=x['source_sku'],dtb_sku=x['dtb_sku'],match_status=x['match_status'],source_name=x['source_name'],dtb_name=r['Name'],source_description=source_desc,dtb_short_description=plain(r['Short description']),dtb_description=dtb_desc,source_product_type=x['source_type'],source_collections=x['source_collections'],dtb_categories=r['Categories'],dtb_product_kind=r['Meta: _dtb_product_kind'],dtb_part_flag=r['Meta: _dtb_is_parts'],dtb_parent=r['Parent'],dtb_variation_label=r['Meta: _dtb_variation_label'],dtb_attribute=r['Attribute 1 value(s)'],dtb_specs=r['Meta: _dtb_specs_json'],source_price=x['source_price'],dtb_regular_price=r['Regular price'],price_delta=str(dtb_price-source_price) if source_price is not None and dtb_price is not None else '',dtb_sale_price=r['Sale price'],source_image_files=images,dtb_image_state=image_state,dtb_images=r['Images'],dtb_inherit_parent_image=r['Meta: _dtb_inherit_parent_image'],dtb_parent_images=parent.get('Images',''),source_available=x['source_available'],dtb_published=r['Published'],source_url=x['source_url']))
out('matched-field-comparison.csv',comparisons);out('source-integrity-findings.csv',integrity or [dict(source_row='',kind='none',detail='No product metadata or unique-variant price differences detected')])
imageissues=[]
for p in (E/'images').iterdir():
 if p.is_file():
  try:
   with Image.open(p) as im: im.verify()
  except Exception as e:imageissues.append(dict(path=str(p.relative_to(E)),issue=str(e)))
out('image-integrity-findings.csv',imageissues or [dict(path='',issue='All files passed Pillow image verification')])
prices=collections.Counter('missing' if num(x['source_price']) is None or num(x['dtb_regular_price']) is None else 'equal' if num(x['source_price'])==num(x['dtb_regular_price']) else 'dtb_higher' if num(x['dtb_regular_price'])>num(x['source_price']) else 'dtb_lower' for x in comparisons)
print(json.dumps({'field_comparisons':len(comparisons),'integrity_issues':len(integrity),'image_decode_issues':len(imageissues),'price_direction':prices,'placeholder_with_source_image':sum(x['dtb_image_state']=='placeholder' and bool(x['source_image_files']) for x in comparisons),'placeholder_without_source_image':sum(x['dtb_image_state']=='placeholder' and not x['source_image_files'] for x in comparisons),'source_no_images':sum(not x['source_image_files'] for x in src),'empty_source_descriptions':sum(not x['source_description'] for x in comparisons)},indent=2))
