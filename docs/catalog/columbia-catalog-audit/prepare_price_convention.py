import csv,json,hashlib,os,shutil,tempfile,datetime
from decimal import Decimal,ROUND_HALF_UP,InvalidOperation
from pathlib import Path
R=Path.cwd();O=R/'docs/catalog/columbia-catalog-audit';C=R/'products/launch/official/dtb_official_catalog.csv';S=O/'source-reconciliation.csv'
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
expected='860e750db92ab2cb5b1632e1f1dd1e589227060dc214555c0459d575578f3cd4'
if sha(C)!=expected:raise SystemExit('Catalog changed since previous update')
with C.open(encoding='utf-8-sig',newline='') as f:r=csv.DictReader(f);fields=r.fieldnames;rows=list(r)
by={x['SKU']:x for x in rows}
with S.open(encoding='utf-8-sig',newline='') as f:matches=[x for x in csv.DictReader(f) if x['match_status']=='exact_identifier']
if len(matches)!=434 or len({x['dtb_sku'] for x in matches})!=434:raise SystemExit('Unexpected matching scope')
changes=[];below_cost=[]
for x in matches:
 row=by[x['dtb_sku']];price=Decimal(x['source_price']);sale=price.quantize(Decimal('.01'),rounding=ROUND_HALF_UP);regular=(sale*Decimal('1.10')).quantize(Decimal('.01'),rounding=ROUND_HALF_UP)
 if price<=0 or sale>=regular:raise SystemExit('Invalid price pair: '+x['dtb_sku'])
 old_sale=row['Sale price'];old_regular=row['Regular price'];row['Sale price']=format(sale,'.2f');row['Regular price']=format(regular,'.2f')
 changes.append({'sku':x['dtb_sku'],'source_price':format(sale,'.2f'),'previous_sale':old_sale,'previous_regular':old_regular,'sale_price':row['Sale price'],'regular_price':row['Regular price']})
 try:
  cost=Decimal(row['Cost of goods'])
  if cost>sale:below_cost.append({'sku':x['dtb_sku'],'sale_price':str(sale),'cost_of_goods':str(cost)})
 except (InvalidOperation,TypeError):pass
candidate=O/'price-convention-candidate.csv'
with candidate.open('w',encoding='utf-8-sig',newline='') as f:w=csv.DictWriter(f,fieldnames=fields,lineterminator='\r\n');w.writeheader();w.writerows(rows)
for name,data,headers in [('price-convention-changes.csv',changes,list(changes[0])),('price-below-cost-review.csv',below_cost,['sku','sale_price','cost_of_goods'])]:
 with (O/name).open('w',encoding='utf-8-sig',newline='') as f:w=csv.DictWriter(f,fieldnames=headers);w.writeheader();w.writerows(data)
print('candidate',sha(candidate),'matches',len(changes),'below catalog cost',len(below_cost),'input',sha(C))
