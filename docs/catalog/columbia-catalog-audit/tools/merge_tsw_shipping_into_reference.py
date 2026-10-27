import csv,json,re,collections,hashlib,difflib,tempfile,os,shutil,datetime
from decimal import Decimal,InvalidOperation
from pathlib import Path
R=Path(__file__).resolve().parents[4];O=R/'docs/catalog/columbia-catalog-audit';P=O/'temporary-reference/columbia-manufacturer-first-WORKING-NOT-FOR-IMPORT.csv';M=O/'temporary-reference/columbia-manufacturer-woocommerce-reference.csv';S=R/'docs/catalog/TSW Product Data - prefixes removed.csv';F=R/'docs/catalog/TSW Product Data - TTT CTT DSS USG.csv';C=R/'products/launch/official/dtb_official_catalog.csv'
H=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
def read(p):
 with p.open(encoding='utf-8-sig',newline='') as f:r=csv.DictReader(f);return r.fieldnames,list(r)
def norm(s):return re.sub(r'[^a-z0-9]','',s.casefold())
def token(s):
 s=s.casefold();s=re.sub(r'\b(columbia|drywall|tools|tool)\b',' ',s);s=re.sub(r'\bstainless\b','ss',s);s=re.sub(r'\bblades\b','blade',s);s=re.sub(r'\bcurved\b','curve',s);return set(re.findall(r'[a-z]+|\d+(?:\.\d+)?',s))
def word(s):return set(re.findall(r'[a-z]+',s.casefold()))-{'columbia','drywall','tools','tool','for','the','with','and','part','handle'}
def nums(s):return set(re.findall(r'\d+(?:\.\d+)?',s))
def num(v):
 if not v.strip():return None
 try:x=Decimal(v.strip())
 except InvalidOperation:return None
 return x if x.is_finite() and x>=0 else None
before={x:H(x) for x in [P,M,S,F,C]};fields,rows=read(P);mf,manufacturer=read(M);_,supplier=read(S);_,prefixed=read(F)
if len(rows)!=1006 or len(manufacturer)!=1006 or fields!=mf or any(a['SKU']!=b['SKU'] for a,b in zip(rows,manufacturer)):raise ValueError('Working/manufacturer references changed or no longer align')
# Reconnect the stripped file to the explicitly prefixed Columbia snapshot using all retained source fields.
stripped=collections.defaultdict(list)
for i,x in enumerate(supplier,2):stripped[(x['prod'].strip().casefold(),x['Prod Description'].strip(),x['Categaory'].strip())].append((i,x))
full=[];amb=[]
for x in prefixed:
 if not x['prod'].startswith('CTT'):continue
 key=(x['prod'][3:].strip().casefold(),x['Prod Description'].strip(),x['Categaory'].strip());zs=stripped[key]
 def eq(z):
  for k in x:
   if k=='prod':continue
   a=x[k].strip();b=z[k].strip()
   if a==b:continue
   na=num(a);nb=num(b)
   if na is None or nb is None or abs(na-nb)>Decimal('0.000000000001')*max(Decimal(1),abs(na),abs(nb)):return False
  return True
 valid=[z for z in zs if eq(z[1])]
 if len(valid)!=1:amb.append((x['prod'],len(valid)));continue
 full.append((x['prod'],valid[0][0],valid[0][1]))
if amb:raise ValueError('Cannot establish prefixed to stripped source rows: '+str(amb[:5]))
exact=collections.defaultdict(list);normalized=collections.defaultdict(list)
for entry in full:exact[entry[0][3:].casefold()].append(entry);normalized[norm(entry[0][3:])].append(entry)
manufacturer_norm=collections.Counter(norm(x['SKU']) for x in manufacturer if x['SKU']);manufacturer_sku=collections.Counter(x['SKU'].strip().casefold() for x in manufacturer if x['SKU']);change=[];audit=[];mapping={'weight':'Weight (lbs)','Length':'Length (in)','Width':'Width (in)','Height':'Height (in)'}
known={'FA379':'TSW/Columbia E-clip size conflict','FA380':'TSW/Columbia E-clip size conflict','MP36':'TSW/Columbia bushing material conflict','FA256':'TSW/Columbia screw type conflict','FA302':'TSW/Columbia screw length conflict','FA262':'supplier product description indicates a possible two-piece pack','FA308':'supplier/manufacturer washer dimensions conflict and existing identifier collision','FA208':'existing manufacturer identifier collision','FA222':'TSW/Columbia fastener size/type conflict','FA259':'TSW/Columbia fastener length conflict','FA279':'TSW/Columbia fastener size/type conflict','PMH':'TSW/Columbia extension-length conflict'}
for i,(row,m) in enumerate(zip(rows,manufacturer),1):
 sku=m['SKU'].strip();title=m['Name'];k=sku.casefold();direct=exact[k];normed=normalized[norm(sku)];match=None;method='unmatched';reasons=[]
 if not sku or manufacturer_sku[k]!=1:reasons.append('blank_or_duplicate_manufacturer_sku')
 elif sku in known:
  reasons.append(known[sku]);match=direct[0] if len(direct)==1 else None
 elif len(direct)>1:reasons.append('multiple_exact_supplier_matches')
 elif len(direct)==1:match=direct[0];method='exact_manufacturer_supplier_identifier'
 elif len(normed)==1 and manufacturer_norm[norm(sku)]==1 and token(title) and token(title)==token(normed[0][2]['Prod Description']):match=normed[0];method='unique_normalized_identifier_and_equal_product_tokens'
 elif len(normed)>1 or (normed and manufacturer_norm[norm(sku)]!=1):reasons.append('normalized_identifier_collision')
 elif normed:reasons.append('fuzzy_name_or_variant_requires_review')
 if match:
  fullsku,source_row,t=match;at=nums(title);bt=nums(t['Prod Description']);wa=word(title);wb=word(t['Prod Description']);overlap=len(wa&wb)/max(1,min(len(wa),len(wb)));sim=difflib.SequenceMatcher(None,title.casefold(),t['Prod Description'].casefold()).ratio()
  if at and bt and not(at<=bt or bt<=at):reasons.append('product_name_numeric_disagreement')
  if method=='exact_manufacturer_supplier_identifier' and overlap<.34 and sim<.52:reasons.append('product_description_disagreement_review')
  if re.search(r'\(\s*\d+\s*\)',t['Prod Description']):reasons.append('supplier_pack_quantity_review')
  if ('old' in wa and 'new' in wb) or ('new' in wa and 'old' in wb):reasons.append('product_revision_conflict')
  if reasons:method='held_for_identity_review'
 else:
  fullsku='';source_row='';t={}
  if reasons:method='held_for_identity_review'
 changes=[];conflicts=[];invalid=[]
 if match and not reasons:
  for source_field,target in mapping.items():
   raw=t[source_field].strip();value=num(raw)
   if raw and value is None:invalid.append(source_field+':invalid');continue
   if value is None or value==0:continue
   if source_field=='weight' and value<Decimal('0.001'):invalid.append('weight:implausibly_small_review');continue
   old=row[target].strip()
   if old:
    oldnum=num(old)
    if oldnum is None or oldnum!=value:conflicts.append(target+': existing='+old+' supplier='+raw)
    continue
   new=format(value.normalize(),'f');row[target]=new;changes.append(target);change.append(dict(source_row=i,manufacturer_sku=sku,manufacturer_name=title,supplier_sku=fullsku,supplier_stripped_csv_row=source_row,supplier_name=t['Prod Description'],match_method=method,field=target,old_value='',new_value=new))
  if invalid:reasons.extend(invalid)
  if conflicts:reasons.append('existing_measurement_disagreement')
 audit.append(dict(source_row=i,manufacturer_sku=sku,manufacturer_name=title,match_status=method,supplier_sku=fullsku,supplier_csv_row=source_row,supplier_name=t.get('Prod Description',''),supplier_stock_unit=t.get('Stocking Unit',''),supplier_selling_unit=t.get('Selling Unit',''),supplier_selling_conversion=t.get('Selling_Conv',''),supplier_weight=t.get('weight',''),supplier_length=t.get('Length',''),supplier_width=t.get('Width',''),supplier_height=t.get('Height',''),fields_filled=' | '.join(changes),measurement_conflicts=' | '.join(conflicts),review_reasons=' | '.join(reasons)))
for p in before:
 if H(p)!=before[p]:raise RuntimeError('Input changed: '+str(p))
if not change:
 print('No new shipping fields to populate; existing audit artifacts preserved.');raise SystemExit(0)
# Only the four shipping fields may change.
_,old=read(P)
for a,b in zip(old,rows):
 if any(a[k]!=b[k] for k in fields if k not in mapping.values()):raise ValueError('Unexpected unrelated change')
backup=O/('archive/rollback-snapshots/manufacturer-first-before-tsw-shipping-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')+'.csv');shutil.copy2(P,backup)
if H(backup)!=before[P]:raise RuntimeError('Backup hash mismatch')
with tempfile.NamedTemporaryFile(mode='w',encoding='utf-8-sig',newline='',dir=P.parent,prefix='.shipping-',suffix='.tmp',delete=False) as f:
 temp=Path(f.name);w=csv.DictWriter(f,fieldnames=fields,lineterminator='\r\n');w.writeheader();w.writerows(rows);f.flush();os.fsync(f.fileno())
if H(P)!=before[P]:temp.unlink();raise RuntimeError('Reference changed while writing')
os.replace(temp,P)
for name,data in [('evidence/tsw/tsw-manufacturer-shipping-audit.csv',audit),('evidence/tsw/tsw-manufacturer-shipping-changes.csv',change)]:
 with (O/name).open('w',encoding='utf-8-sig',newline='') as f:w=csv.DictWriter(f,fieldnames=list(data[0]),lineterminator='\r\n');w.writeheader();w.writerows(data)
_,check=read(P);assert check==rows and H(C)==before[C]
print(json.dumps({'total_rows':len(rows),'match_status':dict(collections.Counter(x['match_status'] for x in audit)),'products_changed':len({x['source_row'] for x in change}),'field_changes':dict(collections.Counter(x['field'] for x in change)),'measurement_conflict_products':sum(bool(x['measurement_conflicts']) for x in audit),'review_products':sum(bool(x['review_reasons']) for x in audit),'positive_weights_after':sum(bool(x['Weight (lbs)']) for x in rows),'all_dimensions_after':sum(all(x[k] for k in ['Length (in)','Width (in)','Height (in)']) for x in rows),'backup':str(backup),'official_unchanged':H(C)},indent=2))
