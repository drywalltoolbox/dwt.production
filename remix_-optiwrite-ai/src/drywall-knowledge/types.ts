export type DrywallDomainFamily =
  | 'taping'
  | 'flat_finishing'
  | 'corner_finishing'
  | 'compound_delivery'
  | 'supporting_equipment'
  | 'parts_and_service';

export type DrywallProductDomain =
  | 'automatic_taper'
  | 'semi_automatic_taper'
  | 'finishing_box'
  | 'nailspotter'
  | 'smoothing_blade'
  | 'corner_finisher'
  | 'corner_applicator_box'
  | 'corner_flusher'
  | 'corner_roller'
  | 'applicator_head'
  | 'compound_tube'
  | 'loading_pump'
  | 'loading_adapter'
  | 'handle_system'
  | 'drywall_sander'
  | 'drywall_stilts'
  | 'storage_case'
  | 'tool_set'
  | 'maintenance_kit'
  | 'replacement_assembly'
  | 'replacement_component'
  | 'commodity_hardware';

export type ContentClass =
  | 'commodity_hardware'
  | 'replacement_component'
  | 'replacement_assembly'
  | 'tool_accessory'
  | 'primary_finishing_tool'
  | 'automatic_equipment'
  | 'stilts'
  | 'kit_set'
  | 'general_product';

export type RelationshipType = 'simple' | 'variable_parent' | 'variation_child';
export type EvidenceRichness = 'sparse' | 'light' | 'standard' | 'rich';
export type ClaimStrength = 'factual' | 'interpretive' | 'performance' | 'comparative' | 'quantitative' | 'superlative' | 'warranty_certification';

export interface KnowledgeReference {
  id: string;
  title: string;
  publisher: string;
  url: string;
  authority: 'official_manufacturer' | 'industry_authority';
}

export interface MechanismKnowledge {
  id: string;
  label: string;
  generalFunction: string;
  evidenceRequiredForPresence: true;
}

export interface BuyerQuestionSet {
  primary: string[];
  secondary: string[];
}

export interface TerminologyPolicy {
  preferred: string[];
  contextualSynonyms: string[];
  relatedButDistinct: string[];
  avoid: string[];
}

export interface WorkflowKnowledge {
  stages: string[];
  upstreamDomains: DrywallProductDomain[];
  downstreamDomains: DrywallProductDomain[];
}

export interface DrywallDomainKnowledge {
  id: DrywallProductDomain;
  family: DrywallDomainFamily;
  label: string;
  tradeRole: string;
  workflow: WorkflowKnowledge;
  buyerQuestions: BuyerQuestionSet;
  evidencePriorities: string[];
  mechanisms: MechanismKnowledge[];
  configurationDimensions: string[];
  systemRelationships: string[];
  compatibilityEvidenceRules: string[];
  terminology: TerminologyPolicy;
  editorialGuidance: string[];
  claimsRequiringEvidence: string[];
  commonCatalogErrors: string[];
  searchIntentPatterns: string[];
  referenceIds: string[];
}

export interface DrywallFamilyKnowledge {
  id: DrywallDomainFamily;
  label: string;
  workflowPrinciples: string[];
  sharedBuyerPriorities: string[];
  sharedEvidenceRules: string[];
  commonConfusions: string[];
}

export interface CatalogEvidencePacket {
  authoritative_facts?: Record<string, unknown>;
  product_class?: ContentClass | string;
  verified_bundle_components?: unknown[];
  verified_child_variations?: unknown[];
  parent_context?: unknown;
  [key: string]: unknown;
}

export interface DomainClassification {
  domainId: DrywallProductDomain | null;
  confidence: 'high' | 'medium' | 'low';
  reasons: string[];
}

export interface DrywallContextRequest {
  domainId: DrywallProductDomain;
  contentClass?: ContentClass;
  relationshipType?: RelationshipType;
  evidenceRichness?: EvidenceRichness;
  hasCompatibility?: boolean;
  hasPackageContents?: boolean;
  hasVariations?: boolean;
  featureSystems?: string[];
  brandId?: string;
}

export interface CompiledDrywallContext {
  domainId: DrywallProductDomain;
  familyId: DrywallDomainFamily;
  sections: {
    authority: string[];
    editorial: string[];
    workflow: string[];
    domain: string[];
    terminology: string[];
    evidence: string[];
    search: string[];
    brand: string[];
  };
  text: string;
}

export interface CatalogEditorKnowledgeResult {
  classification: DomainClassification;
  reviewRequired: boolean;
  context: CompiledDrywallContext | null;
}
