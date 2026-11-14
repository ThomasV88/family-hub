<?php

namespace App\Audit;

use App\Access\Module;

enum AuditEvent: string
{
    case LoginSuccess = 'login_success';
    case LoginFailed = 'login_failed';
    case Lockout = 'lockout';
    case PasswordReset = 'password_reset';
    case AdminCreated = 'admin_created';
    case InviteCreated = 'invite_created';
    case InviteResent = 'invite_resent';
    case InviteRevoked = 'invite_revoked';
    case InviteAccepted = 'invite_accepted';
    case PermissionChanged = 'permission_changed';
    case SessionRevoked = 'session_revoked';
    case SessionRevokedAll = 'session_revoked_all';
    case GoogleLinked = 'google_linked';
    case AccessDenied = 'access_denied';
    case HealthRead = 'health_read';
    case HealthWrite = 'health_write';
    case FinanceRead = 'finance_read';
    case FinanceWrite = 'finance_write';
    case FinanceVisibilityChanged = 'finance_visibility_changed';
    case FinanceAdminSelfAdded = 'finance_admin_self_added';
    case FinanceCategoryChanged = 'finance_category_changed';
    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorDisabled = 'two_factor_disabled';
    case RecoveryCodesRegenerated = 'recovery_codes_regenerated';
    case RecoveryCodeUsed = 'recovery_code_used';
    case PasskeyAdded = 'passkey_added';
    case PasskeyRenamed = 'passkey_renamed';
    case PasskeyRemoved = 'passkey_removed';
    case SecondFactorFailed = 'second_factor_failed';
    case ReauthConfirmed = 'reauth_confirmed';
    case SecondFactorReset = 'second_factor_reset';
    case SecurityPolicyChanged = 'security_policy_changed';
    case MedicalSubjectCreated = 'medical_subject_created';
    case MedicalSubjectDeleted = 'medical_subject_deleted';
    case MedicalAccessChanged = 'medical_access_changed';
    case MedicalAdminSelfAdded = 'medical_admin_self_added';
    case MedicalExported = 'medical_exported';
    case MedicalConsentChanged = 'medical_consent_changed';
    case MedicalAiRunRequested = 'medical_ai_run_requested';
    case MedicalAiRunFinished = 'medical_ai_run_finished';
    case MedicalAiEntryDecided = 'medical_ai_entry_decided';
    case AiAgentSettingChanged = 'ai_agent_setting_changed';
    case AiBulkRunChanged = 'ai_bulk_run_changed';

    /** UI filter groups, in display order. */
    public const GROUPS = ['login', 'login_failed', 'invite', 'permission', 'session_revoked', 'health', 'finance', 'account', 'security'];

    /** The `<module>_read` / `<module>_write` event for an audited module. */
    public static function forModule(Module $module, bool $write): self
    {
        return match ($module) {
            Module::Health => $write ? self::HealthWrite : self::HealthRead,
            Module::Finance => $write ? self::FinanceWrite : self::FinanceRead,
            default => throw new \InvalidArgumentException("Module {$module->value} is not audited."),
        };
    }

    /** Allowed meta keys for this event. */
    public function metaKeys(): array
    {
        return match ($this) {
            self::PermissionChanged => ['from', 'to'],
            self::InviteCreated, self::InviteResent, self::InviteRevoked, self::InviteAccepted => ['email', 'preset'],
            self::SessionRevoked => ['device'],
            self::AccessDenied => ['level'],
            self::FinanceVisibilityChanged => ['change', 'account'],
            self::FinanceAdminSelfAdded => ['account'],
            self::FinanceCategoryChanged => ['change'],
            self::SecondFactorFailed => ['method', 'context'],
            self::ReauthConfirmed => ['method'],
            self::SecurityPolicyChanged => ['setting', 'value'],
            self::HealthRead, self::HealthWrite => ['subject', 'document'],
            self::MedicalSubjectCreated, self::MedicalSubjectDeleted, self::MedicalAdminSelfAdded => ['subject'],
            self::MedicalAccessChanged => ['subject', 'change'],
            self::MedicalExported => ['subject', 'scope', 'format'],
            self::MedicalConsentChanged => ['subject', 'value'],
            self::MedicalAiRunRequested => ['subject', 'document', 'bulk'],
            self::MedicalAiRunFinished => ['subject', 'document', 'status', 'bulk'],
            self::MedicalAiEntryDecided => ['subject', 'document', 'decision'],
            self::AiAgentSettingChanged => ['agent', 'field', 'value'],
            self::AiBulkRunChanged => ['action', 'scope', 'count'],
            default => [],
        };
    }

    /** UI filter group. access_denied depends on the module it was denied for. */
    public function group(?string $module = null): string
    {
        return match ($this) {
            self::LoginSuccess => 'login',
            self::LoginFailed, self::Lockout => 'login_failed',
            self::InviteCreated, self::InviteResent, self::InviteRevoked, self::InviteAccepted => 'invite',
            self::PermissionChanged => 'permission',
            self::SessionRevoked, self::SessionRevokedAll => 'session_revoked',
            self::HealthRead, self::HealthWrite,
            self::MedicalSubjectCreated, self::MedicalSubjectDeleted, self::MedicalAccessChanged,
            self::MedicalAdminSelfAdded, self::MedicalExported, self::MedicalConsentChanged,
            self::MedicalAiRunRequested, self::MedicalAiRunFinished, self::MedicalAiEntryDecided,
            self::AiAgentSettingChanged, self::AiBulkRunChanged => 'health',
            self::FinanceRead, self::FinanceWrite, self::FinanceVisibilityChanged, self::FinanceAdminSelfAdded, self::FinanceCategoryChanged => 'finance',
            self::AccessDenied => $module === 'finance' ? 'finance' : 'health',
            self::PasswordReset, self::AdminCreated, self::GoogleLinked => 'account',
            self::TwoFactorEnabled, self::TwoFactorDisabled, self::RecoveryCodesRegenerated, self::RecoveryCodeUsed,
            self::PasskeyAdded, self::PasskeyRenamed, self::PasskeyRemoved, self::SecondFactorFailed,
            self::ReauthConfirmed, self::SecondFactorReset, self::SecurityPolicyChanged => 'security',
        };
    }

    /**
     * Events stored for a filter group, plus whether access_denied rows belong to it.
     *
     * @return array{events: list<string>, deniedModule: ?string}
     */
    public static function eventsForGroup(string $group): array
    {
        $events = array_values(array_map(
            fn (self $e) => $e->value,
            array_filter(self::cases(), fn (self $e) => $e !== self::AccessDenied && $e->group() === $group),
        ));

        return ['events' => $events, 'deniedModule' => in_array($group, ['health', 'finance'], true) ? $group : null];
    }
}
