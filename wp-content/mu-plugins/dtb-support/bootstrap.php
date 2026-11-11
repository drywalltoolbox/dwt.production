<?php
/**
 * DTB Support Hub — Bootstrap
 *
 * Explicit load order:
 *   1. Domain
 *   2. Infrastructure
 *   3. Services
 *   4. Application
 *   5. Validation
 *   6. REST
 *   7. Admin (only on admin requests)
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

$_dtb_support_dir = __DIR__;

// ── 1. Domain ─────────────────────────────────────────────────────────────────
_dtb_require( $_dtb_support_dir . '/Domain/TicketStatus.php' );
_dtb_require( $_dtb_support_dir . '/Domain/TicketType.php' );
_dtb_require( $_dtb_support_dir . '/Domain/TicketPriority.php' );
_dtb_require( $_dtb_support_dir . '/Domain/TicketEvent.php' );

// ── 2. Infrastructure ─────────────────────────────────────────────────────────
_dtb_require( $_dtb_support_dir . '/Infrastructure/SupportSchemaInstaller.php' );
_dtb_require( $_dtb_support_dir . '/Infrastructure/TicketRepository.php' );
_dtb_require( $_dtb_support_dir . '/Infrastructure/TicketEventRepository.php' );
_dtb_require( $_dtb_support_dir . '/Infrastructure/EmailOutboxRepository.php' );
_dtb_require( $_dtb_support_dir . '/Infrastructure/EmailOutboxProcessor.php' );
_dtb_require( $_dtb_support_dir . '/Infrastructure/TicketNotificationDispatcher.php' );

// ── 3. Services ───────────────────────────────────────────────────────────────
_dtb_require( $_dtb_support_dir . '/Services/TicketSlaService.php' );
_dtb_require( $_dtb_support_dir . '/Services/TicketPriorityScoreService.php' );
_dtb_require( $_dtb_support_dir . '/Services/TicketSnoozeService.php' );
_dtb_require( $_dtb_support_dir . '/Services/TicketMacroService.php' );
_dtb_require( $_dtb_support_dir . '/Services/TicketQueryService.php' );
_dtb_require( $_dtb_support_dir . '/Services/TicketWorkflowService.php' );
_dtb_require( $_dtb_support_dir . '/Services/TicketAutoAssignService.php' );
_dtb_require( $_dtb_support_dir . '/Services/SupportNextActionService.php' );
_dtb_require( $_dtb_support_dir . '/Services/SupportCustomerContextService.php' );

// ── 4. Application ────────────────────────────────────────────────────────────
_dtb_require( $_dtb_support_dir . '/Application/SubmitContactRequest.php' );
_dtb_require( $_dtb_support_dir . '/Application/TransitionTicketStatus.php' );
_dtb_require( $_dtb_support_dir . '/Application/AddTicketReply.php' );

// ── 5. Validation ─────────────────────────────────────────────────────────────
_dtb_require( $_dtb_support_dir . '/Validation/ContactSubmitValidator.php' );

// ── 6. REST ───────────────────────────────────────────────────────────────────
_dtb_require( $_dtb_support_dir . '/Rest/SubmitContactController.php' );
_dtb_require( $_dtb_support_dir . '/Rest/TicketAdminController.php' );
_dtb_require( $_dtb_support_dir . '/Rest/TicketReplyController.php' );
_dtb_require( $_dtb_support_dir . '/Rest/SupportCustomerController.php' );
_dtb_require( $_dtb_support_dir . '/Rest/SupportAdminQueueController.php' );

// ── 7. Admin (only on admin requests) ─────────────────────────────────────────
if ( is_admin() ) {
	_dtb_require( $_dtb_support_dir . '/Admin/SupportPage.php' );
	_dtb_require( $_dtb_support_dir . '/Admin/SupportWorkbench.php' );
}

unset( $_dtb_support_dir );
