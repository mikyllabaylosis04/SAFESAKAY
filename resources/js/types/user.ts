export type UserRole = 'user' | 'operator' | 'administrator' | 'passenger';

export type UserRoleOption = {
    value: UserRole;
    label: string;
    description: string;
};

export type OperatorBoatOption = {
    id: number;
    name: string;
    code: string;
    label: string;
    assigned_user_id: number | null;
};

export type ManagedUser = {
    id: number;
    name: string;
    username: string | null;
    email: string;
    role: UserRole;
    role_label: string;
    role_description: string;
    organization_name: string | null;
    boat_id: number | null;
    boat_name?: string | null;
    boat_label: string | null;
    violation_count?: number | null;
    violations?: Array<{
        id: number;
        strike_label: string;
        incident_date_label: string | null;
        booking_code: string;
        reason: string;
        recorded_at_label: string | null;
    }>;
    is_suspended?: boolean;
    suspension?: {
        reason: string;
        started_at_label: string | null;
        ends_at_label: string | null;
        remaining_label: string | null;
    } | null;
    is_active: boolean;
    status_label: string;
    deactivated_reason: string | null;
    two_factor_enabled: boolean;
    created_at_label: string | null;
    last_active_label: string;
    is_current: boolean;
};

export type UserOrganizationOption = {
    id: number;
    name: string;
};
