import { resolveBrandId } from './brands/registry';
import { classifyDrywallDomain } from './classifyDomain';
import { buildDrywallDomainContext } from './compileContext';
import type { CatalogEditorKnowledgeResult, CatalogEvidencePacket, ContentClass, EvidenceRichness, RelationshipType } from './types';

function objectValue(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {};
}

function text(value: unknown): string { return String(value || '').trim(); }
function list(value: unknown): unknown[] { return Array.isArray(value) ? value : []; }

export function buildCatalogEditorKnowledge(
  packet: CatalogEvidencePacket,
  options: { relationshipType?: RelationshipType; evidenceRichness?: EvidenceRichness; featureSystems?: string[] } = {}
): CatalogEditorKnowledgeResult {
  const classification = classifyDrywallDomain(packet);
  if (!classification.domainId) {
    return { classification, reviewRequired: true, context: null };
  }

  const facts = objectValue(packet.authoritative_facts);
  const identity = objectValue(facts.identity);
  const brandValue = text(facts.brand || identity.brand);
  const compatibility = list(facts.compatibleToolSkus || facts.compatible_tool_skus || facts.replacementPartFor || facts.replacement_part_for);
  const includes = list(facts.includes).length ? list(facts.includes) : list(packet.verified_bundle_components);
  const variations = list(packet.verified_child_variations);

  const context = buildDrywallDomainContext({
    domainId: classification.domainId,
    contentClass: packet.product_class as ContentClass | undefined,
    relationshipType: options.relationshipType,
    evidenceRichness: options.evidenceRichness,
    hasCompatibility: compatibility.length > 0,
    hasPackageContents: includes.length > 0,
    hasVariations: variations.length > 0,
    featureSystems: options.featureSystems,
    brandId: resolveBrandId(brandValue)
  });

  return { classification, reviewRequired: false, context };
}
