#!/usr/bin/env python3
"""DTB official catalog shipping-readiness audit. Read-only; Python standard library.
Source of truth: exact input CSV bytes. Never invents carton size or freight cost.
"""
from __future__ import annotations
import argparse,csv,hashlib,json,math,re,sys
from collections import Counter,defaultdict
from decimal import Decimal,InvalidOperation
from pathlib import Path

VERSION="1.0.0"
REQUIRED=("SKU","Name","Type","Weight (lbs)","Length (in)","Width (in)","Height (in)","Shipping class","Categories","Parent")
FIELDS=["row_number","sku","name","type","published","brand","categories","parent","weight_lb","length_in","width_in","height_in","product_cubic_in","product_longest_in","product_length_plus_girth_in","shipping_class","proposed_class","proposal_evidence","data_quality","needs_packaging_review","carrier_rate_ready","free_shipping_policy","flags","severity"]
ALLOWED_CLASSES={"small-parts","standard-tools","long-bulky-tools","complete-toolsets","oversized-freight"}
PARTS=re.compile(r"\b(spring|screw|washer|o.?ring|gasket|retainer|bolt|valve|seal|pin|nut|bearing|repair part|replacement part|replacement blade|hopper gate)\b",re.I)
KITS=re.compile(r"\b(full set|tool set|finishing set|taping set|starter set|pro performance set|complete set|finishing kit|taping kit|tool kit)\b",re.I)
LONG=re.compile(r"\b(extension handle|angle head handle|flat box handle|pole|handle)\b|\b(?:40|42|48|50|54|60|72)[- ]?(?:in|inch|\\\")\b",re.I)
FREIGHT=re.compile(r"\b(freight|pallet|industrial cart|bulk drum)\b",re.I)
def dec(raw):
    t=str(raw or "").strip()
    if not t:return None
    try:
        d=Decimal(t.replace(",",""))
        return d if d.is_finite() else None
    except InvalidOperation:return None
def fmt(d):
    return str(d) if d is not None else ""
def guess(name,cats,brand,kind):
    text=" ".join((name or "",cats or "",kind or ""))
    if KITS.search(text):return "complete-toolsets","title/category implies kit; operator confirmation required"
    if FREIGHT.search(text):return "oversized-freight","freight wording; operator confirmation required"
    if LONG.search(text) and not PARTS.search(text):return "long-bulky-tools","long handle/extension wording; operator confirmation required"
    if PARTS.search(text) or re.search(r"\breplacement parts?\b",cats or "",re.I):return "small-parts","parts taxonomy/wording; operator confirmation required"
    return "","no reliable text-only evidence"
def issue(arr,sev,key):
    arr.append((sev,key))
def audit(rows,columns):
    output=[];issues=[];sku_rows=defaultdict(list);type_counts=Counter()
    for num,p in enumerate(rows,2):
        sku=(p.get("SKU") or "").strip()
        name=(p.get("Name") or "").strip()
        typ=(p.get("Type") or "").strip().lower()
        parent=(p.get("Parent") or "").strip()
        cats=(p.get("Categories") or "").strip()
        brand=(p.get("Brands") or "").strip()
        kind=(p.get("Meta: _dtb_product_kind") or "").strip()
        published=(p.get("Published") or "").strip()
        shipping=(p.get("Shipping class") or "").strip()
        dims={key:dec(p.get(col)) for key,col in (("weight","Weight (lbs)"),("length","Length (in)"),("width","Width (in)"),("height","Height (in)"))}
        faults=[];type_counts[typ]+=1
        if sku:sku_rows[sku.casefold()].append(num)
        else:issue(faults,"blocker","missing_sku")
        if not name:issue(faults,"error","missing_name")
        if typ not in ("simple","variable","variation","grouped","external","simple, virtual","simple, downloadable"):
            issue(faults,"warning","unexpected_product_type")
        if typ=="variation" and not parent:issue(faults,"blocker","variation_missing_parent")
        if typ=="variation" and not name:issue(faults,"warning","unnamed_variation")
        for key,val in dims.items():
            raw=(p.get({"weight":"Weight (lbs)","length":"Length (in)","width":"Width (in)","height":"Height (in)"}[key]) or "").strip()
            if not raw:
                issue(faults,"warning","missing_"+key)
            elif val is None or val<=0:
                issue(faults,"error","invalid_"+key)
        if not shipping:issue(faults,"warning","unassigned_shipping_class")
        elif shipping not in ALLOWED_CLASSES:issue(faults,"warning","non_dtb_shipping_class_review")
        proposed,basis=guess(name,cats,brand,kind)
        if shipping and proposed and shipping!=proposed:issue(faults,"warning","existing_class_conflicts_with_proposal")
        if not proposed:issue(faults,"review","classification_requires_manual_review")
        v=[dims[k] for k in ("length","width","height")]
        volume=v[0]*v[1]*v[2] if all(z is not None and z>0 for z in v) else None
        longest=max(v) if all(z is not None and z>0 for z in v) else None
        girth=(2*sum(v)-longest) if longest is not None else None
        package_review=(proposed in ("complete-toolsets","long-bulky-tools","oversized-freight") or shipping in ("complete-toolsets","long-bulky-tools","oversized-freight"))
        if package_review:issue(faults,"review","physical_packaging_must_be_verified")
        if dims["weight"] is not None and dims["weight"]>Decimal("70"):issue(faults,"review","over_70lb_product_weight_check_carrier")
        if longest is not None and longest>Decimal("48"):issue(faults,"review","long_product_check_outbound_carton")
        if girth is not None and girth>Decimal("130"):issue(faults,"review","product_dimensions_large_verify_carton_freight")
        if typ in ("variable","variation"):issue(faults,"review","variation_inheritance_and_carton_review")
        if typ=="grouped":issue(faults,"review","grouped_item_not_directly_packaged")
        sev=max(({"review":1,"warning":2,"error":3,"blocker":4}[a] for a,b in faults),default=0)
        name_sev={0:"ok",1:"review",2:"warning",3:"error",4:"blocker"}[sev]
        status="product_dimensions_not_verified_carton" if all(dims[k] is not None and dims[k]>0 for k in dims) else "incomplete_product_measurements"
        # NEVER claim carrier eligibility simply because product dimensions exist.
        row={"row_number":num,"sku":sku,"name":name,"type":typ,"published":published,"brand":brand,"categories":cats,"parent":parent,
            "weight_lb":fmt(dims["weight"]),"length_in":fmt(dims["length"]),"width_in":fmt(dims["width"]),"height_in":fmt(dims["height"]),
            "product_cubic_in":fmt(volume),"product_longest_in":fmt(longest),"product_length_plus_girth_in":fmt(girth),
            "shipping_class":shipping,"proposed_class":proposed,"proposal_evidence":basis,"data_quality":status,
            "needs_packaging_review":"yes" if package_review else "unverified_for_all_products",
            "carrier_rate_ready":"NOT_VERIFIED","free_shipping_policy":"MERCHANT_DECISION_REQUIRED",
            "flags":";".join(dict.fromkeys(b for _,b in faults)),"severity":name_sev}
        output.append(row)
        issues.extend({"row_number":num,"sku":sku,"severity":a,"issue":b} for a,b in faults)
    for sku,line_nums in sku_rows.items():
        if len(line_nums)>1:
            for n in line_nums:issues.append({"row_number":n,"sku":sku,"severity":"blocker","issue":"duplicate_sku"})
    return output,issues,type_counts
def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("catalog",type=Path)
    ap.add_argument("--out",type=Path,required=True)
    args=ap.parse_args()
    if not args.catalog.is_file():ap.error("Input catalog file does not exist.")
    if args.catalog.resolve()==args.out.resolve():ap.error("Output directory must not equal source file.")
    raw=args.catalog.read_bytes()
    sha=hashlib.sha256(raw).hexdigest()
    # Fail closed on ambiguous header, corrupted encoding, malformed/duplicate columns.
    with args.catalog.open("r",encoding="utf-8-sig",newline="") as f:
        reader=csv.DictReader(f)
        cols=reader.fieldnames or []
        absent=[x for x in REQUIRED if x not in cols]
        if absent:ap.error("Missing mandatory columns: "+", ".join(absent))
        if len(cols)!=len(set(cols)):ap.error("Duplicate CSV column names.")
        rows=list(reader)
        if any(None in p for p in rows):ap.error("Malformed CSV rows with excess fields.")
        result,issues,types=audit(rows,cols)
    args.out.mkdir(parents=True,exist_ok=True)
    def save(name,fields,items):
        dst=args.out/name
        with dst.open("w",encoding="utf-8-sig",newline="") as f:
            w=csv.DictWriter(f,fieldnames=fields,extrasaction="ignore")
            w.writeheader();w.writerows(items)
    save("shipping-readiness-matrix.csv",FIELDS,result)
    save("shipping-issues.csv",["row_number","sku","severity","issue"],issues)
    stats=Counter(x["issue"] for x in issues)
    summary={"script_version":VERSION,"input_file":str(args.catalog.resolve()),"input_sha256":sha,"rows":len(rows),
       "column_count":len(cols),"product_types":dict(types),"unique_skus":len({r["sku"].casefold() for r in result if r["sku"]}),
       "severity_counts":dict(Counter(r["severity"] for r in result)),
       "proposed_class_counts":dict(Counter(r["proposed_class"] or "unclassified" for r in result)),
       "current_class_counts":dict(Counter(r["shipping_class"] or "unassigned" for r in result)),
       "issue_counts":dict(stats),
       "carrier_rates_derived":False,"shipping_classes_applied":False,"catalog_mutated":False,
       "limitations":["CSV product measurements are not confirmed outbound cartons.","Classification proposals are non-authoritative.","Variations may inherit attributes; missing direct fields require parent resolution.","Free shipping and carrier prices require merchant approval."]}
    (args.out/"summary.json").write_text(json.dumps(summary,indent=2),encoding="utf-8")
    (args.out/"summary.md").write_text("# DTB shipping catalog audit\n\n"+f"Input: `{args.catalog.name}`  SHA256: `{sha}`\n\nRows: **{len(rows)}**; columns: **{len(cols)}**; unique nonempty SKUs: **{summary['unique_skus']}**.\n\n## Issues\n"+ "".join(f"- {k}: {v}\n" for k,v in stats.most_common())+"\nAll proposals require physical-packaging and merchant pricing verification. **No catalog data was changed.**\n",encoding="utf-8")
    print(json.dumps({"status":"completed","rows":len(rows),"unique_skus":summary["unique_skus"],"input_sha256":sha,"out":str(args.out),"top_issues":stats.most_common(12)},indent=2))
if __name__=="__main__":main()
