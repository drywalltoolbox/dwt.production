import type { DrywallDomainKnowledge, DrywallProductDomain } from './types';
import { AUTOMATIC_TAPER } from './domains/automaticTaper';
import { SEMI_AUTOMATIC_TAPER } from './domains/semiAutomaticTaper';
import { FINISHING_BOX } from './domains/finishingBox';
import { NAILSPOTTER } from './domains/nailspotter';
import { SMOOTHING_BLADE } from './domains/smoothingBlade';
import { CORNER_FINISHER } from './domains/cornerFinisher';
import { CORNER_APPLICATOR_BOX } from './domains/cornerApplicatorBox';
import { CORNER_FLUSHER } from './domains/cornerFlusher';
import { CORNER_ROLLER } from './domains/cornerRoller';
import { APPLICATOR_HEAD } from './domains/applicatorHead';
import { COMPOUND_TUBE } from './domains/compoundTube';
import { LOADING_PUMP } from './domains/loadingPump';
import { LOADING_ADAPTER } from './domains/loadingAdapter';
import { HANDLE_SYSTEM } from './domains/handleSystem';
import { DRYWALL_SANDER } from './domains/drywallSander';
import { DRYWALL_STILTS } from './domains/drywallStilts';
import { STORAGE_CASE } from './domains/storageCase';
import { TOOL_SET } from './domains/toolSet';
import { MAINTENANCE_KIT } from './domains/maintenanceKit';
import { REPLACEMENT_ASSEMBLY } from './domains/replacementAssembly';
import { REPLACEMENT_COMPONENT } from './domains/replacementComponent';
import { COMMODITY_HARDWARE } from './domains/commodityHardware';

export const DOMAIN_REGISTRY: Record<DrywallProductDomain, DrywallDomainKnowledge> = {
  automatic_taper: AUTOMATIC_TAPER,
  semi_automatic_taper: SEMI_AUTOMATIC_TAPER,
  finishing_box: FINISHING_BOX,
  nailspotter: NAILSPOTTER,
  smoothing_blade: SMOOTHING_BLADE,
  corner_finisher: CORNER_FINISHER,
  corner_applicator_box: CORNER_APPLICATOR_BOX,
  corner_flusher: CORNER_FLUSHER,
  corner_roller: CORNER_ROLLER,
  applicator_head: APPLICATOR_HEAD,
  compound_tube: COMPOUND_TUBE,
  loading_pump: LOADING_PUMP,
  loading_adapter: LOADING_ADAPTER,
  handle_system: HANDLE_SYSTEM,
  drywall_sander: DRYWALL_SANDER,
  drywall_stilts: DRYWALL_STILTS,
  storage_case: STORAGE_CASE,
  tool_set: TOOL_SET,
  maintenance_kit: MAINTENANCE_KIT,
  replacement_assembly: REPLACEMENT_ASSEMBLY,
  replacement_component: REPLACEMENT_COMPONENT,
  commodity_hardware: COMMODITY_HARDWARE
};
