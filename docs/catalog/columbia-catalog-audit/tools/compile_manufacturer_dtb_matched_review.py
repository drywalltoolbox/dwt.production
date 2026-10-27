import csv,hashlib,collections,json,os,tempfile
from pathlib import Path
R=Path(__file__).resolve().parents[4];O=R/'docs/catalog/columbia-catalog-audit';D=O/'temporary-reference';M=D/'columbia-manufacturer-woocommerce-reference.csv';B=D/'dtb-current-columbia-woocommerce-reference.csv';W=D/'columbia-manufacturer-first-WORKING-NOT-FOR-IMPORT.csv';X=O/'review/manufacturer-rebuild-dispositions.csv';F=O/'archive/previous-audits/fuzzy-price-changes.csv';C=R/'products/launch/official/dtb_official_catalog.csv';inputs=[M,B,W,X,F,C];sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest();before={p:sha(p) for p in inputs}
def read(p):
 with p.open(encoding='utf-8-sig',newline='') as f:r=csv.DictReader(f);return r.fieldnames,list(r)
fields,m=read(M);f2,b=read(B);f3,w=read(W);_,x=read(X);_,fuzzy=read(F);_,full=read(C)
if fields!=f2 or fields!=f3 or len(fields)!=151 or len(m)!=1006 or len(m)!=len(w) or len(m)!=len(x):raise ValueError('Input alignment or schema differs')
if any(a['SKU']!=b['SKU'] or a['SKU']!=c['source_sku'] for a,b,c in zip(m,w,x)):raise ValueError('Manufacturer row alignment failed')
dtb={a['SKU']:a for a in b};official={a['SKU']:a for a in full}
if len(dtb)!=515 or any(official.get(k)!=v for k,v in dtb.items()):raise ValueError('DTB reference no longer matches current official catalog')
fuzzyby={a['source_row']:a for a in fuzzy};costflag={a['sku'] for a in read(O/'evidence/tsw/price-below-cost-review.csv')[1]};tsw=collections.defaultdict(list)
for a in read(O/'evidence/tsw/tsw-manufacturer-shipping-changes.csv')[1]:tsw[(a['manufacturer_sku'],a['field'])].append(a)
selected=[];used=set()
for i,(man,work,record) in enumerate(zip(m,w,x),1):
 match_type='';target='';basis=''
 if record['disposition']=='exact_existing_match':match_type='exact_sku';target=record['dtb_sku'];basis='Manufacturer SKU and DTB SKU match uniquely after case-insensitive comparison.'
 elif record['source_row'] in fuzzyby:
  f=fuzzyby[record['source_row']];match_type='strong_format_candidate';target=f['dtb_sku'];basis=f['match_evidence']+'; distinct SKUs require identity confirmation.'
 else:continue
 if target not in dtb or target in used:raise ValueError('Missing or reused target '+target)
 used.add(target);old=dtb[target]
 if match_type=='exact_sku' and man['SKU'].strip().casefold()!=target.strip().casefold():raise ValueError('Exact identifier mismatch')
 if match_type=='strong_format_candidate' and fuzzyby[record['source_row']]['source_sku']!=man['SKU']:raise ValueError('Fuzzy source mismatch')
 flags=[]
 if match_type!='exact_sku':flags.append('alternate_identifier_not_manufacturer_verified')
 if target in costflag or man['SKU'] in costflag:flags.append('sale_below_recorded_cost')
 if man['SKU'] in ('FA379','FA380'):flags.append('manufacturer_tsw_size_conflict')
 if man['SKU']=='MP36':flags.append('manufacturer_tsw_material_conflict')
 if man['SKU']=='FA262':flags.append('supplier_pack_quantity_review')
 if man['SKU']=='CR1':flags.append('prior_supplier_cost_identity_conflict')
 flags.append('field_decisions_pending_review')
 selected.append((i,man,old,work,record,match_type,basis,' | '.join(flags)))
if collections.Counter(v[5] for v in selected)!={'exact_sku':434,'strong_format_candidate':11}:raise ValueError('Unexpected match counts')
metadata=['match_id','match_type','source_product_id','source_variant_id','manufacturer_sku','dtb_sku','manufacturer_name','dtb_name','source_url','match_basis','review_flags']
product_fields=metadata+[f'{origin}: {field}' for field in fields for origin in ('Manufacturer','DTB','Working')]
product=[];reviews=[]
source_authoritative={'Name','Description','Sale price','Regular price','Images','Tags'}
structural={'Type','Parent','Meta: _dtb_parent_product_sku','Meta: _dtb_variation_axis','Meta: _dtb_variation_value','Meta: _dtb_variation_label','Meta: _dtb_default_variation_sku','Meta: _dtb_variation_sort'}
measure={'Weight (lbs)','Length (in)','Width (in)','Height (in)'}
identity={'SKU','Meta: _dtb_manufacturer_sku','Meta: _dtb_mpn','Meta: schema_mpn','GTIN, UPC, EAN, or ISBN','Meta: _veeqo_mapped_sku','Meta: _veeqo_sellable_id','Meta: _veeqo_product_id'}
for i,man,old,work,rec,kind,basis,flags in selected:
 meta=dict(match_id=f'M{i:04d}',match_type=kind,source_product_id=rec['source_product_id'],source_variant_id=rec['source_variant_id'],manufacturer_sku=man['SKU'],dtb_sku=old['SKU'],manufacturer_name=man['Name'],dtb_name=old['Name'],source_url=rec['source_url'],match_basis=basis,review_flags=flags);row=meta.copy()
 for field in fields:
  a=man[field];b=old[field];v=work[field];row['Manufacturer: '+field]=a;row['DTB: '+field]=b;row['Working: '+field]=v
  decision='';proposal='';review='pending';reason=''
  if not a and not b and not v:decision='no_value_supplied';review='no_action';reason='All three reference fields are empty.'
  elif field=='SKU':
   if kind=='exact_sku':decision='manufacturer';proposal=a;reason='Exact source and DTB SKU match.'
   else:decision='identity_migration_review';reason='Manufacturer and DTB SKUs differ; validate alias and WooCommerce/Veeqo impacts.'
  elif field in identity:
   decision='identity_review';proposal=v or b or a;reason='Protected identifier: reconcile source and existing mappings before adopting.'
  elif field in structural:
   decision='dtb_structure_review';proposal=b;reason='Existing WooCommerce product type and parent/variation relationship must be preserved.'
  elif field=='Images':decision='manufacturer_images';proposal=a;reason='Use only mapped local export images; source blank stays empty. Verify upload and variation fallback.'
  elif field in source_authoritative and a:decision='manufacturer';proposal=a;reason='Manufacturer explicitly supplies the field; pricing uses the requested 10% regular-price convention.' if field in ('Sale price','Regular price') else 'Manufacturer explicitly supplies this product fact.'
  elif field=='Categories':decision='taxonomy_review';proposal=b;reason='DTB taxonomy and manufacturer collections require explicit functional reconciliation.'
  elif field in measure:
   decision='shipping_measurement_review';proposal=v or b;reason='TSW or DTB measurement is provisional; verify product identity, units and package-versus-product meaning.'
   if (man['SKU'],field) in tsw:reason+=' Working value was populated from the TSW shipping audit.'
  elif field in ('Cost of goods','Meta: _brikpanel_cogs'):decision='supplier_cost_review';proposal=b;reason='DTB cost is not established by manufacturer retail price; verify supplier evidence and unit.'
  elif field in ('Stock','In stock?','Backorders allowed?'):decision='runtime_inventory_policy_review';proposal=b;reason='Retain for review; Veeqo is inventory authority and CSV stock is not live stock.'
  elif field in ('Brands','Meta: schema_brand','Meta: _dtb_brand','Meta: _dtb_brand_key','Meta: _dtb_brand_label'):decision='brand_review';proposal=b or a;reason='Reconcile manufacturer vendor and DTB canonical brand fields.'
  elif a and b and a==b and (not v or v==a):decision='same_value';proposal=a;reason='Manufacturer and DTB fields agree; fact still subject to product review.'
  elif not a and (b or v):decision='dtb_or_working_review';proposal=v or b;reason='Manufacturer has no structured value; retain candidate pending independent verification.'
  elif a:decision='manufacturer_field_review';proposal=a;reason='Source value exists; validate semantic mapping into the DTB field.'
  else:decision='review';proposal=v or b;reason='Review conflicting or incomplete evidence.'
  reviews.append(dict(match_id=meta['match_id'],match_type=kind,manufacturer_sku=man['SKU'],dtb_sku=old['SKU'],manufacturer_name=man['Name'],dtb_name=old['Name'],source_url=rec['source_url'],field=field,manufacturer_value=a,dtb_value=b,working_value=v,recommended_source=decision,proposed_value=proposal,review_status=review,reason=reason,product_review_flags=flags))
 product.append(row)
for p in inputs:
 if sha(p)!=before[p]:raise RuntimeError('Input changed while compiling: '+str(p))
outputs=[(O/'review/columbia-matched-products-side-by-side.csv',product_fields,product),(O/'review/columbia-matched-fields-review.csv',list(reviews[0]),reviews)]
for path,headers,rows in outputs:
 with tempfile.NamedTemporaryFile(mode='w',encoding='utf-8-sig',newline='',dir=path.parent,prefix='.matched-review-',suffix='.tmp',delete=False) as f:
  tmp=Path(f.name);writer=csv.DictWriter(f,fieldnames=headers,lineterminator='\r\n',extrasaction='raise');writer.writeheader();writer.writerows(rows);f.flush();os.fsync(f.fileno())
 os.replace(tmp,path)
with (O/'review/columbia-matched-products-side-by-side.csv').open(encoding='utf-8-sig',newline='') as f:r=csv.DictReader(f);check=list(r);assert r.fieldnames==product_fields and check==product
with (O/'review/columbia-matched-fields-review.csv').open(encoding='utf-8-sig',newline='') as f:r=csv.DictReader(f);check=list(r);assert check==reviews
if any(sha(p)!=before[p] for p in inputs):raise RuntimeError('Input changed during write')
print(json.dumps({'matched_products':len(product),'match_types':dict(collections.Counter(x['match_type'] for x in product)),'product_columns':len(product_fields),'field_rows':len(reviews),'field_recommendations':dict(collections.Counter(x['recommended_source'] for x in reviews)),'rows_below_recorded_cost':sum('sale_below_recorded_cost' in x['review_flags'] for x in product),'official_sha256_unchanged':sha(C)},indent=2))
