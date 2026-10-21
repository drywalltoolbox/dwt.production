import type { CatalogEvidencePacket, DomainClassification, DrywallProductDomain } from './types';

const RULES: Array<{ domainId: DrywallProductDomain; patterns: RegExp[] }> = [
  { domainId:'maintenance_kit', patterns:[/\bmaintenance kit\b/i,/\brepair kit\b/i,/\bservice kit\b/i,/\bseal kit\b/i,/\brebuild kit\b/i,/\bgasket kit\b/i,/\bo-ring kit\b/i] },
  { domainId:'tool_set', patterns:[/\btool\s*set\b/i,/\btoolset\b/i,/\bfinishing set\b/i,/\btaping set\b/i,/\bcombo set\b/i,/\bstarter set\b/i,/\bpro set\b/i,/\bmaster set\b/i,/\bpackage set\b/i] },
  { domainId:'automatic_taper', patterns:[/\bautomatic (?:drywall )?taper\b/i,/\beasyclean.*taper\b/i,/\bauto taper\b/i,/\bbazooka\b/i,/\btaper\b/i] },
  { domainId:'semi_automatic_taper', patterns:[/\bsemi[- ]automatic taper\b/i,/\bsemi[- ]auto taper\b/i,/\bbanjo taper\b/i,/\bbanjo\b/i,/\btaping banjo\b/i] },
  { domainId:'finishing_box', patterns:[/\bfinishing box\b/i,/\bflat box\b/i,/\bfat boy\b/i,/\bmaxxbox\b/i,/\bquickbox\b/i,/\bfinish box\b/i,/\bpower flat box\b/i] },
  { domainId:'corner_applicator_box', patterns:[/\bcorner applicator box\b/i,/\bcorner box\b/i,/\bangle box\b/i,/\bmud box\b/i] },
  { domainId:'corner_finisher', patterns:[/\bcorner finisher\b/i,/\bangle head\b/i,/\banglehead\b/i,/\bcorner angle head\b/i] },
  { domainId:'corner_flusher', patterns:[/\bcorner flusher\b/i,/\bdirect flusher\b/i,/\bcombo flusher\b/i,/\bcorner glazer\b/i,/\bglazer\b/i,/\bflusher\b/i] },
  { domainId:'corner_roller', patterns:[/\bcorner roller\b/i,/\binside corner roller\b/i,/\boutside corner roller\b/i,/\broller\b/i] },
  { domainId:'compound_tube', patterns:[/\bcompound tube\b/i,/\bmud tube\b/i,/\bminishot\b/i,/\bapplicator tube\b/i] },
  { domainId:'loading_pump', patterns:[/\bloading pump\b/i,/\bcompound pump\b/i,/\bmud pump\b/i,/\bpowerfill\b/i,/\bdrywall pump\b/i] },
  { domainId:'loading_adapter', patterns:[/\bgooseneck\b/i,/\bbox filler\b/i,/\bfiller adapter\b/i,/\bfiller valve\b/i,/\bpump adapter\b/i,/\bfiller nozzle\b/i] },
  { domainId:'nailspotter', patterns:[/\bnail\s*spotter\b/i,/\bfastener spotter\b/i,/\bscrew spotter\b/i] },
  { domainId:'smoothing_blade', patterns:[/\bsmoothing blade\b/i,/\bskim(?:ming)? blade\b/i,/\bskim(?:ming)? spatula\b/i,/\bwipe[- ]?down knife\b/i,/\bjoint knife\b/i,/\btaping knife\b/i,/\bputty knife\b/i,/\bdrywall knife\b/i,/\btrowel\b/i,/\bhawk\b/i,/\bmud pan\b/i,/\bblade\b/i] },
  { domainId:'drywall_sander', patterns:[/\bdrywall sander\b/i,/\bddm sander\b/i,/\bpole sander\b/i,/\bradial sander\b/i,/\bsanding head\b/i,/\bsanding disc\b/i,/\bsanding sponge\b/i] },
  { domainId:'drywall_stilts', patterns:[/\bdrywall stilts?\b/i,/\bdura[- ]?stilts?\b/i,/\bsur[- ]?pro.*stilts?\b/i,/\bstilts?\b/i] },
  { domainId:'storage_case', patterns:[/\btaper case\b/i,/\btool case\b/i,/\broad case\b/i,/\bstorage case\b/i,/\bcarrying case\b/i,/\bhard case\b/i] },
  { domainId:'handle_system', patterns:[/\bbox handle\b/i,/\bcorner.*handle\b/i,/\bextension handle\b/i,/\bhandle adapter\b/i,/\bangle head adapter\b/i,/\bextendable handle\b/i,/\bhandle\b/i] },
  { domainId:'applicator_head', patterns:[/\bapplicator head\b/i,/\binside corner applicator\b/i,/\bflat applicator\b/i,/\bmud head\b/i,/\bcorner applicator\b/i] }
];

const ASSEMBLY_RE = /\b(?:assembly|housing assembly|head assembly|wheel assembly|brake assembly|drive assembly|axle assembly|plate assembly|control assembly)\b/i;
const HARDWARE_RE = /\b(?:screw|bolt|nut|washer|o[- ]?ring|pin|clip|retainer|spacer|spring|bushing|bearing)\b/i;
const COMPONENT_RE = /\b(?:blade|cable|wheel|seal|gasket|shaft|bracket|cover|cap|valve|gear|axle|hinge|roller|bearing|bushing)\b/i;

function flatten(value: unknown): string {
  if (value == null) return '';
  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') return String(value);
  if (Array.isArray(value)) return value.map(flatten).join(' ');
  if (typeof value === 'object') return Object.values(value as Record<string, unknown>).map(flatten).join(' ');
  return '';
}

export function classifyDrywallDomain(packet: CatalogEvidencePacket | Record<string, unknown>): DomainClassification {
  const facts = (packet as CatalogEvidencePacket).authoritative_facts || packet;
  const text = flatten(facts).replace(/\s+/g,' ').trim();
  const lower = text.toLowerCase();
  const productKind = flatten((facts as Record<string, unknown>).product_kind || (facts as Record<string, unknown>).productKind).toLowerCase();
  const productClass = flatten((packet as CatalogEvidencePacket).product_class || (facts as Record<string, unknown>).product_class).toLowerCase();
  const isPart = productKind === 'part' || productClass === 'replacement_component' || productClass === 'replacement_assembly' || productClass === 'commodity_hardware' || /\bparts?\b/.test(flatten((facts as Record<string, unknown>).categories).toLowerCase());

  if (isPart) {
    if (ASSEMBLY_RE.test(text) || productClass === 'replacement_assembly') return { domainId:'replacement_assembly', confidence:'high', reasons:['part identity contains an assembly term'] };
    if (HARDWARE_RE.test(text) || productClass === 'commodity_hardware') return { domainId:'commodity_hardware', confidence:'high', reasons:['part identity contains commodity-hardware terminology'] };
    if (COMPONENT_RE.test(text) || productClass === 'replacement_component') return { domainId:'replacement_component', confidence:'high', reasons:['part identity contains a functional component term'] };
    return { domainId:'replacement_component', confidence:'medium', reasons:['catalog identifies the product as a part but no narrower part subtype was established'] };
  }

  for (const rule of RULES) {
    if (rule.patterns.some(pattern => pattern.test(text))) {
      return { domainId:rule.domainId, confidence:'high', reasons:[`matched ${rule.domainId} domain terminology`] };
    }
  }

  if (productClass === 'stilts') return { domainId:'drywall_stilts', confidence:'high', reasons:['product class indicates drywall stilts'] };
  if (productClass === 'kit_set') return { domainId:'tool_set', confidence:'high', reasons:['product class indicates tool set/kit'] };
  if (productClass === 'automatic_equipment') return { domainId:'automatic_taper', confidence:'medium', reasons:['product class indicates automatic equipment'] };
  if (productClass === 'tool_accessory') return { domainId:'handle_system', confidence:'medium', reasons:['product class indicates tool accessory'] };
  if (productClass === 'primary_finishing_tool') return { domainId:'finishing_box', confidence:'medium', reasons:['product class indicates primary finishing tool'] };

  if (/\bapplicator\b/i.test(lower)) return { domainId:'applicator_head', confidence:'medium', reasons:['matched generic applicator terminology without a box-specific signal'] };
  if (/\bhandle\b/i.test(lower)) return { domainId:'handle_system', confidence:'medium', reasons:['matched handle terminology'] };
  if (/\bcase\b/i.test(lower)) return { domainId:'storage_case', confidence:'medium', reasons:['matched case terminology'] };
  if (/\bpump\b/i.test(lower)) return { domainId:'loading_pump', confidence:'medium', reasons:['matched pump terminology'] };
  if (/\bknife\b|\btrowel\b|\bpan\b|\bblade\b|\bhawk\b/i.test(lower)) return { domainId:'smoothing_blade', confidence:'medium', reasons:['matched hand finishing blade/trowel terminology'] };

  return { domainId:'replacement_component', confidence:'medium', reasons:['catalog product mapped to general domain knowledge baseline'] };
}
