export const TRADE_WORKFLOW = {
  tape_application: 'Applies drywall tape and joint compound to joints and, depending on the tool system, internal angles.',
  tape_embedding: 'Seats tape into compound and removes/manages excess compound so the tape is properly embedded.',
  flat_joint_finishing: 'Applies and feathers successive compound coats over taped flat joints.',
  butt_joint_finishing: 'Finishes non-tapered butt joints, often requiring wider feathering to manage the joint profile.',
  inside_corner_application: 'Delivers compound into internal corners before or during corner finishing, depending on the tool system.',
  inside_corner_finishing: 'Wipes, feathers, or finishes both sides of an internal angle with a dedicated corner-finishing head.',
  fastener_finishing: 'Applies compound over fastener depressions with a purpose-built spotting tool.',
  compound_loading: 'Transfers joint compound from a container into automatic or semi-automatic finishing tools through the appropriate interface.',
  compound_delivery: 'Carries/supplies compound to an applicator or finishing head during semi-automatic corner/flat workflows.',
  smoothing_skimming: 'Smooths or skims applied compound across larger surfaces with a wide blade system.',
  sanding: 'Refines dried compound surfaces after application/finishing; sanding equipment does not replace taping or compound-application tools.',
  service_repair: 'Maintains or restores tool operation through verified replacement parts, assemblies, and maintenance kits.'
} as const;

export const WORKFLOW_GUARDRAILS = [
  'Do not claim every finisher uses the same sequence; workflows vary by system, crew, compound, and project.',
  'Do not treat compound application, tape embedding, and final finishing as interchangeable operations.',
  'When product evidence does not establish coat stage or workflow position, describe the general domain role without inventing a specific stage.'
] as const;
