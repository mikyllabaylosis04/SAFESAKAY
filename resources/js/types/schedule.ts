export type RegularTripScheduleMode = 'timed' | 'rush';

export type RegularTripBoarding = {
    active: boolean;
    phase: 'idle' | 'boarding' | 'ready_to_depart' | 'ready_for_review' | 'departed';
    phase_label: string;
    wait_minutes: number;
    started_at: string | null;
    started_at_label: string | null;
    elapsed_seconds: number;
    remaining_seconds: number;
    remaining_label: string;
    waiting_duration_label?: string | null;
    limit_reached: boolean;
    boat_full: boolean;
    ready_to_depart: boolean;
    ready_for_review: boolean;
    auto_departs: boolean;
    note: string;
};

export type RegularTripStatus =
    | 'scheduled'
    | 'on_trip'
    | 'delayed'
    | 'completed'
    | 'cancelled'
    | 'off_duty';

export type RegularTripStatusOption = {
    value: RegularTripStatus;
    label: string;
};

export type ScheduleBoatOption = {
    id: number;
    name: string;
    code: string;
    capacity: number;
    status?: string;
    service_type: string;
    picture_url: string | null;
    operator_name: string | null;
};

export type OrganizationRouteOption = {
    id: number;
    name: string;
    origin: string;
    destination: string;
    directions: [string, string];
    regular_fare?: number | null;
    regular_fare_label?: string | null;
    student_fare?: number | null;
    student_fare_label?: string | null;
    senior_fare?: number | null;
    senior_fare_label?: string | null;
    discount_percent?: number | null;
    student_discount_percent?: number | null;
    senior_discount_percent?: number | null;
};

export type RegularTripRecord = {
    id: number;
    route: string;
    direction: string;
    is_outbound: boolean;
    heading_label: string;
    trip_type?: string;
    cycle_number?: number;
    trip_number_label?: string;
    return_of_id?: number | null;
    rotation_order: number;
    queue_order?: number | null;
    departed_at?: string | null;
    status_changed_at?: string | null;
    leave_order_label: string;
    queue_position?: number | null;
    queue_position_label?: string;
    trips_today?: number;
    is_lineup?: boolean;
    can_depart?: boolean;
    can_arrive_destination?: boolean;
    can_arrive_origin?: boolean;
    can_complete_return?: boolean;
    can_depart_return?: boolean;
    can_encode_passengers?: boolean;
    reference_number?: string;
    waiting_duration_label?: string | null;
    remaining_seats?: number;
    at_capacity?: boolean;
    can_complete?: boolean;
    can_set_off_duty?: boolean;
    can_set_on_duty?: boolean;
    departed_at_label?: string | null;
    arrived_at_label?: string | null;
    completed_at_label?: string | null;
    scheduled_time_label?: string;
    actual_departure_label?: string;
    actual_arrival_label?: string;
    boarding?: RegularTripBoarding;
    trip_date: string;
    trip_date_label: string;
    schedule_mode: RegularTripScheduleMode;
    has_departure_time: boolean;
    departure_time: string;
    departure_time_label: string;
    passenger_count: number;
    remarks?: string | null;
    revenue?: number;
    revenue_label?: string;
    fare_counts?: {
        regular: number;
        student: number;
        senior: number;
    };
    fares?: {
        regular_fare: number | null;
        regular_fare_label: string | null;
        student_fare: number | null;
        student_fare_label: string | null;
        senior_fare: number | null;
        senior_fare_label: string | null;
        discount_percent: number;
        student_discount_percent?: number;
        senior_discount_percent?: number;
    };
    status: RegularTripStatus;
    status_label: string;
    remarks: string | null;
    boat: {
        id: number;
        name: string;
        code: string;
        capacity: number;
        status?: string;
        status_label?: string;
        is_active?: boolean;
        service_type?: string;
        picture_url: string | null;
        operator_name: string | null;
    };
};

export type TripPassengerRecord = {
    id: number;
    full_name: string;
    age: number;
    fare_type?: 'regular' | 'student' | 'senior';
    fare_type_label?: string;
    fare_amount?: number | null;
    fare_amount_label?: string;
    gender: string;
    contact_number: string;
    remarks: string | null;
};

export type RegularTripHistoryItem = {
    id: number;
    trip_number: number;
    leg_label?: string;
    date: string;
    date_label: string;
    departure_time_label: string | null;
    boarding_started_at_label?: string | null;
    arrived_at_label?: string | null;
    completed_at_label: string | null;
    route: string;
    passenger_count: number;
    revenue?: number;
    revenue_label?: string;
    status: RegularTripStatus;
    status_label: string;
};

export type RegularTripDetailsRecord = RegularTripRecord & {
    boat_capacity: number;
    remaining_seats: number;
    at_capacity: boolean;
    can_encode_passengers: boolean;
    passengers: TripPassengerRecord[];
    boat_revenue_today?: number;
    boat_revenue_today_label?: string;
    outbound_revenue?: number;
    outbound_revenue_label?: string;
    return_revenue?: number;
    return_revenue_label?: string;
    total_revenue?: number;
    total_revenue_label?: string;
    paired_return?: {
        id: number;
        status: RegularTripStatus;
        status_label: string;
        started: boolean;
        departed: boolean;
        completed: boolean;
    } | null;
};
