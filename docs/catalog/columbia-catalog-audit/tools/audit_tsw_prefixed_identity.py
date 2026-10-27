import csv,json,re,collections,hashlib,difflib
from pathlib import Path
R=Path(__file__).resolve().parents[4];O=Path(__file__).resolve().parent.parent;S=R/'docs/catalog/TSW Product Data - TTT CTT DSS USG.csv';C=R/'products/launch/official/dtb_official_catalog.csv';P=O/'evidence/tsw/price-below-cost-review.csv';T=R/'products/catalog/source/tsw-costs.csv'
def read(p):
 with p.open(encoding='utf-8-sig',newline='') as f:return list(csv.DictReader(f))
def key(s):return (s or '').strip().casefold()
def normal(s):return re.sub(r'[^a-z0-9]','',key(s))
def words(s):
 s=key(s).replace('"',' inch ').replace("'",' foot ');s=re.sub(r'\b(columbia|tools|taping|drywall|the|for|and|part|parts|tool)\b',' ',s);s=re.sub(r'\b(ss|stainless)\b','stainless',s);s=re.sub(r'\b(hd|head)\b','head',s);return re.findall(r'\d+(?:[./-]\d+)*|[a-z]+',s)
def identnums(s):return set(re.findall(r'\d+(?:[./-]\d+)*',key(s)))
def safe(s):
 s=str(s or '');return "'"+s if s.startswith(('=','+','-','@','\t','\r')) else s
def h(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def write(name,data):
 with (O/name).open('w',encoding='utf-8-sig',newline='') as f:w=csv.DictWriter(f,fieldnames=list(data[0]));w.writeheader();w.writerows([{k:safe(v) for k,v in x.items()} for x in data])
allrows=read(S);tsw=[x for x in allrows if x['prod'].upper().startswith('CTT')];low=read(P);cat={x['SKU']:x for x in read(C)};src=read(O/'archive/previous-audits/source-reconciliation.csv');fuzzy=read(O/'archive/previous-audits/fuzzy-price-changes.csv');linked={x['dtb_sku']:x for x in src if x['dtb_sku']}
for x in fuzzy:
 matches=[z for z in src if z['source_row']==x['source_row']]
 if len(matches)==1:linked[x['dtb_sku']]=matches[0]
cost=collections.defaultdict(list)
for x in read(T):
 if key(x['brand'])=='columbia tools':cost[key(x['sku'])].append(x)
by_exact=collections.defaultdict(list);by_norm=collections.defaultdict(list)
for x in tsw:by_exact[key(x['prod'][3:])].append(x);by_norm[normal(x['prod'][3:])].append(x)
results=[];candidate=[]
for l in low:
 sku=l['sku'];r=cat[sku];m=linked.get(sku,{});d=m.get('source_name') or r['Name'];direct=by_exact[key(sku)];normalized=by_norm[normal(sku)] if not direct else [];found=direct or normalized; selected=found[0] if len(found)==1 else None
 disposition='exact_supplier_id' if len(direct)==1 else 'normalized_id_review' if len(normalized)==1 else 'ambiguous_id' if len(found)>1 else 'unresolved';notes=''
 if sku in ('FA379','FA380'):disposition='identifier_size_conflict';notes='Columbia and TSW assign opposite E-clip sizes; preserve conflict.'
 if sku=='MP36':disposition='identifier_material_conflict';notes='Columbia describes steel bushing; TSW and existing DTB describe Nylatron bushing.'
 if sku=='FA262':disposition='exact_id_pack_review';notes='TSW description indicates (2); Columbia quantity unspecified.'
 if sku=='CR1':disposition='unresolved_prior_ct1_conflict';notes='Prior CT1 mapping is TSW taper head; DTB CR1 identifies corner roller.'
 costs=cost.get(key(selected['prod'][3:]),[]) if selected else [];v=costs[0] if len(costs)==1 else {};t=selected or {}
 results.append(dict(dtb_sku=sku,dtb_name=r['Name'],columbia_sku=m.get('source_sku',''),columbia_name=m.get('source_name',''),columbia_url=m.get('source_url',''),sale_price=l['sale_price'],catalog_cost=l['cost_of_goods'],tsw_sku=t.get('prod',''),tsw_name=t.get('Prod Description',''),stock_unit=t.get('Stocking Unit',''),selling_unit=t.get('Selling Unit',''),selling_conversion=t.get('Selling_Conv',''),pack_qty=t.get('Pack Qty',''),upc=t.get('UPC_List','') or t.get('All_Barcodes',''),tsw_cost_sku=v.get('sku',''),tsw_cost=v.get('supplier_cost',''),match_status=disposition,notes=notes))
 a=words(d);aset=set(a);n1=identnums(d); rank=[]
 for x in tsw:
  b=words(x['Prod Description']);bset=set(b);n2=identnums(x['Prod Description']);similar=difflib.SequenceMatcher(None,' '.join(a),' '.join(b)).ratio();overlap=len(aset & bset)/max(1,len(aset|bset));score=.55*similar+.45*overlap
  if normal(sku)==normal(x['prod'][3:]):score=max(score,.90)
  if score>=.42:rank.append((score,x,similar,overlap,n1,n2))
 for score,x,similar,overlap,n1,n2 in sorted(rank,key=lambda z:z[0],reverse=True)[:5]:
  cc=cost.get(key(x['prod'][3:]),[]); item=cc[0] if len(cc)==1 else {}
  candidate.append(dict(dtb_sku=sku,dtb_name=r['Name'],manufacturer_name=d,tsw_sku=x['prod'],tsw_name=x['Prod Description'],score=f'{score:.3f}',basis=('normalized_identifier; ' if normal(sku)==normal(x['prod'][3:]) else '')+f'title_similarity={similar:.3f}; token_overlap={overlap:.3f}',number_disagreement='yes' if n1 and n2 and n1!=n2 else 'no',tsw_cost_sku=item.get('sku',''),tsw_cost=item.get('supplier_cost',''),disposition=('conflict_supplier_description_vs_manufacturer_for_candidate_sku' if (sku,x['prod']) in [('FA249','CTTFA256'),('FA320','CTTFA302')] else 'review_only_not_identity_proof')))
write('evidence/tsw/tsw-prefixed-73-identity-review.csv',results);write('evidence/tsw/tsw-prefixed-73-candidates.csv',candidate)
print(json.dumps({'counts':dict(collections.Counter(x['match_status'] for x in results)),'candidates':len(candidate),'source_hash':h(S),'prefix_counts':dict(collections.Counter(x['prod'][:3] for x in allrows))},indent=2));
