import csv,json,hashlib,re,collections,xml.etree.ElementTree as ET
from pathlib import Path
from zipfile import ZipFile
from decimal import Decimal
R=Path(__file__).resolve().parents[3];O=Path(__file__).resolve().parent;T=R/'products/catalog/source/tsw-costs.csv';C=R/'products/launch/official/dtb_official_catalog.csv';W=R/'docs/reference/data/TSW/TSW Product Data.xlsx';P=O/'price-below-cost-review.csv';B=R/'docs/reference/data/TSW/TSW Product Data - DTB Brands.csv';M=R/'scripts/supplier-catalog/results/cost/tsw-supplier-cost-migration-report.json'
def read(p):
 with p.open(encoding='utf-8-sig',newline='') as f:return list(csv.DictReader(f))
def norm(s):return re.sub(r'[^a-z0-9]','',s.casefold())
def key(s):return s.strip().casefold()
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def safe(s):
 s=str(s or '');return "'"+s if s.startswith(('=','+','-','@','\t','\r')) else s
low=read(P);cat={x['SKU']:x for x in read(C)};tsw=[x for x in read(T) if key(x['brand'])=='columbia tools'];brandrows=[x for x in read(B) if key(x['brand'])=='columbia tools'];prior=json.loads(M.read_text(encoding='utf8'));priorby={x['catalog_sku']:x for x in prior['resolved_mappings']};source=read(O/'source-reconciliation.csv');srcidx=collections.defaultdict(list)
for x in source:
 if x['dtb_sku']:srcidx[x['dtb_sku']].append(x)
for x in read(O/'fuzzy-price-changes.csv'):
 srcidx[x['dtb_sku']].extend(y for y in source if y['source_row']==x['source_row'])
ns={'s':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'};raw=[]
with ZipFile(W) as z:
 shared=[''.join(si.itertext()) for si in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('s:si',ns)];root=ET.fromstring(z.read('xl/worksheets/sheet1.xml'))
 for line in root.findall('s:sheetData/s:row',ns):
  cells={}
  for c in line.findall('s:c',ns):
   v=c.find('s:v',ns);v=v.text if v is not None else '';col=re.sub(r'\d','',c.attrib['r']);cells[col]=shared[int(v)] if c.get('t')=='s' and v else v
  if cells.get('B','').upper().startswith('CTT'):
   raw.append({'row':line.get('r'),'supplier_sku':cells.get('B',''),'name':cells.get('F',''),'category':cells.get('A',''),'stock_unit':cells.get('G',''),'sell_unit':cells.get('H',''),'conversion':cells.get('I',''),'pack_qty':cells.get('N',''),'upc':cells.get('T','') or cells.get('S','')})
byexact=collections.defaultdict(list);bynorm=collections.defaultdict(list);rawidx=collections.defaultdict(list)
for x in tsw:byexact[key(x['sku'])].append(x);bynorm[norm(x['sku'])].append(x)
for x in raw:rawidx[norm(x['supplier_sku'][3:])].append(x)
out=[];candidates=[]
for x in low:
 sku=x['sku'];row=cat[sku];sources=srcidx[sku];source=sources[0] if len(sources)==1 else {};exact=byexact[key(sku)];priorrow=priorby.get(sku,{});rawmatches=rawidx[norm(sku)]; cost_status='no_matching_tsw_cost';reason='No exact supplier cost record in supplied TSW cost snapshot.'
 if len(exact)==1:
  t=exact[0];cost_status='exact_sku_cost_agrees' if Decimal(x['cost_of_goods'])==Decimal(t['supplier_cost']) else 'exact_sku_cost_differs';reason='Brand-scoped exact SKU; compare product description and selling unit.'
  if sku in ('FA379','FA380'):cost_status='identity_conflict';reason='Manufacturer and TSW assign opposite E-clip sizes to this SKU; cost amounts agree but item identity is unresolved.'
  if sku=='FA262':cost_status='pack_quantity_review';reason='TSW workbook description ends (2), while manufacturer lists a socket head without pack quantity; workbook selling unit is EACH. Determine cost and price per actual sellable pack.'
 elif len(exact)>1:cost_status='duplicate_tsw_cost';reason='Multiple TSW cost records claim SKU.'
 elif sku=='CR1' and priorrow.get('supplier_sku')=='CT1':cost_status='prior_mapping_conflict';reason='Prior mapping CT1 is TSW taper head; DTB CR1 is identified as corner-roller head. TSW cost cannot be attributed to CR1 on this evidence.'
 t=exact[0] if len(exact)==1 else {};rawmatch=rawmatches[0] if len(rawmatches)==1 else {};rawstatus='exact_supplier_sku' if rawmatch and key(rawmatch['supplier_sku'])==key('CTT'+sku) else 'normalized_candidate' if rawmatch else 'ambiguous' if len(rawmatches)>1 else 'not_found'
 out.append(dict(dtb_sku=sku,dtb_name=row['Name'],manufacturer_sku=source.get('source_sku',''),manufacturer_name=source.get('source_name',''),manufacturer_url=source.get('source_url',''),sale_price=x['sale_price'],catalog_cost=x['cost_of_goods'],tsw_cost_sku=t.get('sku',''),tsw_cost_name=t.get('product_name',''),tsw_cost=t.get('supplier_cost',''),cost_status=cost_status,reason=reason,tsw_product_status=rawstatus,tsw_product_count=len(rawmatches),tsw_product_sku=rawmatch.get('supplier_sku',''),tsw_product_name=rawmatch.get('name',''),tsw_excel_row=rawmatch.get('row',''),tsw_stock_unit=rawmatch.get('stock_unit',''),tsw_sell_unit=rawmatch.get('sell_unit',''),tsw_sell_conversion=rawmatch.get('conversion',''),tsw_pack_qty=rawmatch.get('pack_qty',''),tsw_upc=rawmatch.get('upc',''),prior_mapping_sku=priorrow.get('supplier_sku',''),prior_mapping_basis=priorrow.get('match_basis',''),next_action='Confirm supplier quote, SKU identity and per-unit pricing' if cost_status not in ('exact_sku_cost_agrees',) else 'Confirm snapshot is current and check unit terms before purchasing'))
 if not exact:
  for y in sorted(tsw,key=lambda y:__import__('difflib').SequenceMatcher(None,norm(sku),norm(y['sku'])).ratio(),reverse=True)[:3]:
   sim=__import__('difflib').SequenceMatcher(None,norm(sku),norm(y['sku'])).ratio()
   if sim>=.55:candidates.append(dict(dtb_sku=sku,dtb_name=row['Name'],supplier_sku=y['sku'],supplier_name=y['product_name'],supplier_cost=y['supplier_cost'],identifier_similarity=f'{sim:.3f}',status='unverified_candidate_not_cost_evidence'))
for name,items in [('tsw-cost-cross-reference.csv',out),('tsw-unmatched-candidates.csv',candidates)]:
 with (O/name).open('w',encoding='utf-8-sig',newline='') as f:
  writer=csv.DictWriter(f,fieldnames=items[0].keys());writer.writeheader();writer.writerows([{k:safe(v) for k,v in x.items()} for x in items])
summary={'source_hashes':{str(p.relative_to(R)).replace('\\','/'):sha(p) for p in [T,C,W,P,B,M]},'rows':len(out),'cost_status':dict(collections.Counter(x['cost_status'] for x in out)),'product_status':dict(collections.Counter(x['tsw_product_status'] for x in out)),'raw_ctt_rows':len(raw),'tsw_columbia_cost_rows':len(tsw),'unmatched_candidate_rows':len(candidates)}
(O/'tsw-cost-audit-summary.json').write_text(json.dumps(summary,indent=2)+'\n',encoding='utf8');print(json.dumps(summary,indent=2))
