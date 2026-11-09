import type {
    BoatOperator,
    BoatPictureItem,
    BoatStatus,
    RentalDestinationOption,
} from '@/types/boat';

export type BookingBoatRecord = {
    id: number;
    name: string;
    code: string;
    type?: string;
    capacity: number;
    license_no: string | null;
    registration_date: string | null;
    registration_date_label: string | null;
    status: BoatStatus;
    status_label: string;
    registration_status?: string;
    registration_status_label?: string;
    service_type: string;
    service_type_label: string;
    service_availability?: string[];
    service_availability_label?: string;
    safety_information: string | null;
    safety_equipment?: string[];
    life_jacket_quantity?: number | null;
    rental_destinations?: RentalDestinationOption[];
    is_active: boolean;
    picture_url: string | null;
    pictures?: BoatPictureItem[];
    home_port: string;
    is_open_for_booking: boolean;
    availability_label: string;
    operator: BoatOperator | null;
};

export type BookingPassengerRecord = {
    id: number;
    full_name: string;
    age: number;
    gender: string;
    contact_number: string | null;
};

export type BoatBookingStatus =
    | 'pending'
    | 'awaiting_payment'
    | 'confirmed'
    | 'ongoing'
    | 'weather_hold'
    | 'reschedule_pending'
    | 'completed'
    | 'passenger_no_show'
    | 'cancelled'
    | 'rejected';

export type BoatBookingStatusOption = {
    value: BoatBookingStatus;
    label: string;
};

export type BookingBoatOption = {
    id: number;
    name: string;
    code: string;
    capacity: number;
    picture_url: string | null;
    operator_name: string | null;
    rental_destinations?: RentalDestinationOption[];
};

export type BoatBookingRecord = {
    id: number;
    code: string;
    customer_name: string;
    contact_number: string | null;
    package: string;
    destination: string;
    pickup_point?: string | null;
    purpose: string | null;
    booking_date: string;
    booking_date_label: string;
    original_booking_date?: string;
    original_booking_date_label?: string;
    weather_reason?: string | null;
    reschedule_status?: string | null;
    reschedule_status_label?: string | null;
    requested_booking_date?: string | null;
    requested_booking_date_label?: string | null;
    reschedule_rejection_reason?: string | null;
    can_review_reschedule?: boolean;
    reschedule_history?: Array<{
        id: number;
        from_date_label: string;
        to_date_label: string;
        status: string;
        status_label: string;
        rejection_reason?: string | null;
        payment_carried_forward?: boolean;
        down_payment_amount_label?: string | null;
        created_at_label?: string | null;
    }>;
    start_time: string;
    start_time_label: string;
    created_at?: string | null;
    created_at_unix?: number;
    created_at_label?: string | null;
    created_at_time_label?: string | null;
    passenger_count: number;
    status: BoatBookingStatus;
    display_status?: BoatBookingStatus | 'weather_reschedule_confirmed' | 'reschedule_confirmed';
    weather_reschedule_confirmed?: boolean;
    status_label: string;
    remarks: string | null;
    remarks_display?: string | null;
    can_cancel?: boolean;
    no_show_incident?: {
        kind: 'operator' | 'passenger';
        label: string;
        status: string;
        status_label: string;
        needs_action: boolean;
        active?: boolean;
        reported_at_label: string | null;
        reported_by: string | null;
        amount_paid_label: string | null;
        dispute_status_label: string | null;
    } | null;
    is_refunded?: boolean;
    can_approve?: boolean;
    can_complete?: boolean;
    complete_blocked_reason?: string | null;
    complete_settles_balance?: boolean;
    cancellation_reason?: string | null;
    cancelled_by_name?: string | null;
    cancelled_at_label?: string | null;
    walk_in?: boolean;
    walk_in_payment_method?: 'cash' | 'gcash' | null;
    walk_in_cash_received_label?: string | null;
    walk_in_change_label?: string | null;
    payment?: {
        submitted: boolean;
        rental_price: string | number | null;
        rental_price_label: string | null;
        down_payment_percent: string | number | null;
        down_payment_percent_label: string | null;
        down_payment_amount: string | number | null;
        down_payment_amount_label: string | null;
        remaining_balance: string | number | null;
        remaining_balance_label: string | null;
        payment_status: 'unpaid' | 'for_verification' | 'verified' | 'rejected';
        payment_status_label: string;
        payment_rejection_reason?: string | null;
        gcash_reference: string | null;
        receipt_url: string | null;
        receipt_filename?: string | null;
        payment_submitted_at_label: string | null;
        payment_verified_at_label?: string | null;
        payment_verified_by_name?: string | null;
        balance_status?: 'unpaid' | 'paid';
        balance_status_label?: string | null;
        balance_method?: string | null;
        balance_method_label?: string | null;
        balance_reference?: string | null;
        balance_paid_at_label?: string | null;
        balance_recorded_by_name?: string | null;
        overall_status?: string;
        overall_status_label?: string;
        commission_enabled?: boolean;
        commission_percentage_label?: string | null;
        commission_amount_label?: string | null;
        operator_net_label?: string | null;
        gcash_payee?: 'operator' | 'barangay';
        gcash_payee_label?: string | null;
        payment_amount_submitted_label?: string | null;
        can_verify?: boolean;
        can_reject?: boolean;
        can_record_balance?: boolean;
        commission_id?: number | null;
        commission_status?: string | null;
        commission_status_label?: string | null;
        commission_can_verify?: boolean;
        read_only?: boolean;
        downpayment_method_label?: string | null;
        provider_payment_id?: string | null;
    };
    activities?: Array<{
        id: number | string;
        event: string;
        title: string;
        actor_name: string | null;
        occurred_at: string | null;
        occurred_at_label: string | null;
    }>;
    passengers?: BookingPassengerRecord[];
    feedback?: {
        id: number;
        rating: number;
        tags: string[];
        comment: string | null;
        submitted_at_label: string | null;
    } | null;
    boat: {
        id: number;
        name: string;
        code: string;
        capacity: number;
        picture_url: string | null;
        operator_name: string | null;
        operator_contact_number?: string | null;
        type?: string;
        license_no?: string | null;
        registration_date_label?: string | null;
        home_port?: string;
        safety_information?: string | null;
        safety_equipment?: string[];
    };
    trip_confirmation_status?: 'awaiting_confirmation' | 'confirmed' | 'cannot_operate';
    trip_confirmation_status_label?: string;
    trip_confirmation_method?: string | null;
    trip_confirmation_method_label?: string | null;
    trip_confirmed_at_label?: string | null;
    trip_confirmed_by_label?: string | null;
    trip_confirmation_remarks?: string | null;
    trip_confirmation_conflict?: boolean;
    operator_cannot_operate_reason?: string | null;
    operator_contact_number?: string | null;
    operator_contact_number_label?: string | null;
    can_admin_confirm_trip?: boolean;
    can_admin_cannot_operate?: boolean;
    can_send_confirmation_request?: boolean;
    show_trip_manage?: boolean;
    confirmation_record?: {
        mode: 'awaiting_confirmation' | 'confirmed' | 'cannot_operate';
        confirmation_request_sent_at_label?: string | null;
        confirmation_request_via_label?: string | null;
        confirmed_by_label?: string | null;
        confirmation_type_label?: string | null;
        confirmation_at_label?: string | null;
        operator_response_label?: string | null;
        remarks?: string | null;
        is_read_only?: boolean;
    };
    replacement?: {
        id: number;
        status: string;
        status_label: string;
        original_boat_name?: string | null;
        replacement_boat_name?: string | null;
        replacement_operator_name?: string | null;
        operator_decline_reason?: string | null;
        can_approve?: boolean;
        replacement_options?: Array<{
            id: number;
            name: string;
            code: string;
            capacity: number;
            operator_name?: string | null;
            operator_user_id?: number | null;
        }>;
    } | null;
    attendance?: {
        operator_meeting_point_arrived_at_label?: string | null;
        waiting_period_ends_at_label?: string | null;
        passengers?: Array<{
            id: number;
            full_name: string;
            attendance_status: string;
            attendance_status_label: string;
            checked_in_at_label?: string | null;
        }>;
    };
};
