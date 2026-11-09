export type BoatStatus =
    | 'available'
    | 'on_duty'
    | 'on_trip'
    | 'maintenance'
    | 'unavailable';

export type BoatRegistrationStatus =
    | 'pending_verification'
    | 'registered'
    | 'rejected';

export type BoatService = 'regular_trip' | 'private_booking';

export type RentalDestinationOption = {
    id: number;
    destination: string;
    origin: string;
    name: string;
    rental_price?: number | null;
    rental_price_label?: string | null;
    down_payment_percent?: number | null;
    down_payment_percent_label?: string | null;
    down_payment_amount?: number | null;
    down_payment_amount_label?: string | null;
    remaining_balance?: number | null;
    remaining_balance_label?: string | null;
    location?: string | null;
    picture_url?: string | null;
};

export type StatusOption = {
    value: string;
    label: string;
};

export type BoatPictureItem = {
    id: number;
    url: string;
};

export type BoatOperator = {
    user_id?: number | null;
    full_name: string;
    first_name?: string | null;
    middle_name?: string | null;
    last_name?: string | null;
    age?: number | null;
    gender?: string | null;
    contact_number?: string | null;
    address?: string | null;
    experience_years?: number | null;
    emergency_contact_name?: string | null;
    emergency_contact_number?: string | null;
    picture_url: string | null;
    admin_use_only: boolean;
    is_active?: boolean;
    status_label?: string;
};

export type BoatHistoryItem = {
    id: number;
    category: 'trip' | 'schedule' | 'rental' | string;
    title: string;
    details: string | null;
    occurred_on: string;
    occurred_on_label: string;
};

export type BoatSafetyCheckItem = {
    value: string;
    label: string;
    checked: boolean;
};

export type BoatUsageSummary = {
    regular_trips: number;
    exclusive_rentals: number;
    total_trips: number;
    last_trip_label: string | null;
};

export type BoatRecord = {
    id: number;
    name: string;
    code: string;
    capacity: number;
    type: string;
    license_no: string | null;
    registration_date: string | null;
    registration_date_label: string | null;
    registered_in_safesakay?: string | null;
    registered_in_safesakay_label?: string | null;
    status: BoatStatus;
    status_label: string;
    registration_status: BoatRegistrationStatus;
    registration_status_label: string;
    service_type: string;
    service_type_label: string;
    service_availability: BoatService[];
    service_availability_label: string;
    rental_destination_ids?: number[];
    rental_destinations?: RentalDestinationOption[];
    safety_information: string | null;
    safety_equipment?: string[];
    life_jacket_quantity?: number | null;
    safety_checklist?: BoatSafetyCheckItem[];
    safety_last_checked_label?: string | null;
    is_active: boolean;
    picture_url: string | null;
    pictures?: BoatPictureItem[];
    home_port?: string;
    usage?: BoatUsageSummary;
    recent_activity?: BoatHistoryItem[];
    operator: BoatOperator | null;
    history: {
        trips: BoatHistoryItem[];
        schedules: BoatHistoryItem[];
        rentals: BoatHistoryItem[];
    };
    operator_no_show_violations?: {
        id: number;
        strike_number: number;
        strike_label: string;
        incident_date_label: string;
        booking_code: string;
        booking_id: number;
        reason: string;
        recorded_at_label: string | null;
    }[];
};
