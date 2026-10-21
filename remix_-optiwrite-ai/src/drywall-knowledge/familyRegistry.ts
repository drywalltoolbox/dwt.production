import type { DrywallDomainFamily, DrywallFamilyKnowledge } from './types';
import { TAPING_FAMILY } from './families/taping';
import { FLAT_FINISHING_FAMILY } from './families/flatFinishing';
import { CORNER_FINISHING_FAMILY } from './families/cornerFinishing';
import { COMPOUND_DELIVERY_FAMILY } from './families/compoundDelivery';
import { SUPPORTING_EQUIPMENT_FAMILY } from './families/supportingEquipment';
import { PARTS_FAMILY } from './families/parts';

export const FAMILY_REGISTRY: Record<DrywallDomainFamily, DrywallFamilyKnowledge> = {
  taping: TAPING_FAMILY,
  flat_finishing: FLAT_FINISHING_FAMILY,
  corner_finishing: CORNER_FINISHING_FAMILY,
  compound_delivery: COMPOUND_DELIVERY_FAMILY,
  supporting_equipment: SUPPORTING_EQUIPMENT_FAMILY,
  parts_and_service: PARTS_FAMILY
};
