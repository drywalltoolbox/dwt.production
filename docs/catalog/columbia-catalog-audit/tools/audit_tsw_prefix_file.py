import csv,json,re,collections,hashlib,xml.etree.ElementTree as ET
from pathlib import Path
from zipfile import ZipFile
from decimal import Decimal,InvalidOperation
R=Path(__file__).resolve().parents[4];O=Path(__file__).resolve().parent.parent;P=R/'docs/catalog/TSW Product Data - prefixes removed.csv';W=R/'docs/reference/data/TSW/TSW Product Data.xlsx';C=R/'products/launch/official/dtb_official_catalog.csv';B=O/'evidence/tsw/price-below-cost-review.csv';T=R/'products/catalog/source/tsw-costs.csv'
def read(p):
 with p.open(encoding='utf-8-sig',newline='') as f:return list(csv.DictReader(f))
def key(s):return (s or '').strip().casefold()
def norm(s):return re.sub(r'[^a-z0-9]','',key(s))
def h(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def same(a,b):
 if a==b:return True
 try:
  if not a or not b:return False
  da,db=Decimal(a),Decimal(b)
  return abs(da-db) <= Decimal('0.000000000001') * max(Decimal(1),abs(da),abs(db))
 except InvalidOperation:return False
def safe(v):
 s=str(v if v is not None else '');return "'"+s if s.startswith(('=','+','-','@','\t','\r')) else s
def write(name,items,fields=None):
 if fields is None:fields=list(items[0])
 with (O/name).open('w',encoding='utf-8-sig',newline='') as f:
  w=csv.DictWriter(f,fieldnames=fields);w.writeheader();w.writerows([{k:safe(v) for k,v in x.items()} for x in items])
rows=read(P);ns={'s':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'};orig=[]
with ZipFile(W) as z:
 shared=[''.join(si.itertext()) for si in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('s:si',ns)];root=ET.fromstring(z.read('xl/worksheets/sheet1.xml'))
 for line in root.findall('s:sheetData/s:row',ns)[1:]:
  cells={}
  for c in line.findall('s:c',ns):
   v=c.find('s:v',ns);v=v.text if v is not None else '';col=re.sub(r'\d','',c.attrib['r']);cells[col]=shared[int(v)] if c.get('t')=='s' and v else v
  orig.append((line.get('r'),cells))
if len(rows)!=len(orig):raise SystemExit('row counts differ; positional provenance unsafe')
cols=list(rows[0]);letters='ABCDEFGHIJKLMNOPQRST';mis=collections.Counter();prefix=collections.Counter();duplicates=collections.defaultdict(list);columbia=[]
for i,(r,(sheetrow,x)) in enumerate(zip(rows,orig),2):
 original=x.get('B','');v=r['prod'];suffix=original.casefold().endswith(v.casefold()) and bool(v);pre=original[:-len(v)] if suffix else ''
 if not suffix:raise SystemExit(f'identifier not suffix at CSV row {i}')
 prefix[pre]+=1
 for j,(label,col) in enumerate(zip(cols,letters)):
  if col=='B':continue
  if not same(r[label],x.get(col,'')):mis[label]+=1
 record={'csv_row':i,'xlsx_row':sheetrow,'prefix':pre,'original_sku':original,'row':r};duplicates[key(v)].append(record)
 if pre=='CTT':columbia.append(record)
exact=collections.defaultdict(list); normalized=collections.defaultdict(list)
for x in columbia:exact[key(x['row']['prod'])].append(x);normalized[norm(x['row']['prod'])].append(x)
cat=read(C);colrows=[x for x in cat if key(x['Brands']) in ('columbia tools','columbia taping tools') or key(x['Meta: _dtb_brand_key']) in ('columbia-tools','columbia-taping-tools')]; tswcost=collections.defaultdict(list)
for x in read(T):
 if key(x['brand'])=='columbia tools':tswcost[key(x['sku'])].append(x)
low={x['sku']:x for x in read(B)};supplier_audit={x['dtb_sku']:x for x in read(O/'evidence/tsw/tsw-cost-cross-reference.csv')}
output=[]
for c in colrows:
 sku=c['SKU'];direct=exact[key(sku)];alt=normalized[norm(sku)] if not direct else [];found=direct or alt;u=found[0] if len(found)==1 else None;r=u['row'] if u else {}; priced=tswcost[key(sku)];prev=supplier_audit.get(sku,{})
 status='family_parent' if c['Type']=='variable' else ('exact_one' if len(direct)==1 else 'exact_ambiguous' if len(direct)>1 else 'normalized_candidate' if len(alt)==1 else 'normalized_ambiguous' if len(alt)>1 else 'not_found')
 if c['Type']=='variable':u=None;r={};found=[]
 output.append(dict(dtb_sku=sku,dtb_name=c['Name'],dtb_type=c['Type'],dtb_parent=c['Parent'],dtb_sale_price=c['Sale price'],dtb_cost=c['Cost of goods'],below_cost_in_prior_73='yes' if sku in low else 'no',match_status=status,match_count=len(found),tsw_csv_row=u['csv_row'] if u else '',tsw_excel_row=u['xlsx_row'] if u else '',tsw_full_sku=u['original_sku'] if u else '',tsw_stripped_sku=r.get('prod',''),tsw_description=r.get('Prod Description',''),tsw_category=r.get('Categaory',''),stocking_unit=r.get('Stocking Unit',''),selling_unit=r.get('Selling Unit',''),selling_conversion=r.get('Selling_Conv',''),pack_qty=r.get('Pack Qty',''),box_qty=r.get('BOX Qty',''),tsw_weight=r.get('weight',''),tsw_length=r.get('Length',''),tsw_width=r.get('Width',''),tsw_height=r.get('Height',''),tsw_upc=r.get('UPC_List','') or r.get('All_Barcodes',''),cost_source_sku=priced[0]['sku'] if len(priced)==1 else '',cost_source_value=priced[0]['supplier_cost'] if len(priced)==1 else '',cost_disposition=prev.get('cost_status','not_evaluated_in_73_audit'),cost_evidence_note=prev.get('reason','')))
dups=[]
for sku,rs in duplicates.items():
 if len(rs)>1:
  for x in rs:dups.append(dict(stripped_sku=x['row']['prod'],group_count=len(rs),original_sku=x['original_sku'],prefix=x['prefix'],csv_row=x['csv_row'],category=x['row']['Categaory'],description=x['row']['Prod Description']))
write('evidence/tsw/tsw-prefix-file-columbia-cross-reference.csv',output);write('evidence/tsw/tsw-prefix-file-73-cross-reference.csv',[x for x in output if x['below_cost_in_prior_73']=='yes']);write('evidence/tsw/tsw-prefix-file-duplicate-identifiers.csv',dups)
summary={'hashes':{str(x.relative_to(R)).replace('\\','/'):h(x) for x in [P,W,C,B,T]},'rows':len(rows),'xlsx_rows':len(orig),'columns':cols,'non_sku_field_differences':dict(mis),'removed_prefix_counts':dict(prefix.most_common()),'duplicate_stripped_sku_groups':sum(len(v)>1 for v in duplicates.values()),'duplicate_stripped_sku_rows':sum(len(v) for v in duplicates.values() if len(v)>1),'columbia_source_rows':len(columbia),'columbia_official_rows':len(output),'columbia_official_status':dict(collections.Counter(x['match_status'] for x in output)),'below_cost_73_status':dict(collections.Counter(x['match_status'] for x in output if x['below_cost_in_prior_73']=='yes')),'below_cost_rows_reconciled':sum(x['below_cost_in_prior_73']=='yes' for x in output)}
(O/'evidence/tsw/tsw-prefix-file-audit-summary.json').write_text(json.dumps(summary,indent=2)+'\n',encoding='utf8');print(json.dumps(summary,indent=2))
