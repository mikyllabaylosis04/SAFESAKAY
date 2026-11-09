export type ReportKind =
    | 'daily'
    | 'manifest'
    | 'usage'
    | 'private'
    | 'rotation'
    | 'regular'
    | 'rental'
    | 'passenger'
    | 'payment'
    | 'boats';

export type ReportRevenueRecord = {
    id: string;
    date: string;
    date_time_label: string;
    type: 'regular' | 'rental';
    type_label: string;
    reference: string;
    boat_id: number;
    boat_name: string;
    customer: string;
    amount: number;
    amount_label: string;
    counted_amount: number;
    method: string;
    method_key: string;
    status: string;
    status_label: string;
};

export type ManifestPassenger = {
    id: number;
    full_name: string;
    age: number;
    gender: string;
    contact_number: string;
    remarks?: string | null;
    fare_amount?: number | null;
    fare_amount_label?: string | null;
};

export type ManifestBoatOption = {
    id: number;
    name: string;
};

export type ManifestStatusOption = {
    value: string;
    label: string;
};

export type ReportRegularTrip = {
    id: number;
    code: string;
    route: string;
    direction: string;
    trip_date: string;
    trip_date_label: string;
    has_departure_time?: boolean;
    departure_time: string;
    departure_time_label: string;
    actual_departure_label?: string;
    actual_arrival_label?: string;
    boarding_started_at_label?: string | null;
    waiting_duration_label?: string | null;
    cycle_number?: number;
    return_of_id?: number | null;
    passenger_count: number;
    is_outbound?: boolean;
    leg_label?: string;
    trip_type_detail?: string;
    status: string;
    status_label: string;
    report_status?: string;
    report_status_label?: string;
    remarks: string | null;
    revenue?: number;
    revenue_label?: string;
    passengers: ManifestPassenger[];
    boat: {
        id: number;
        name: string;
        code: string;
        capacity: number;
        operator_name: string | null;
    };
};

export type ReportBooking = {
    id: number;
    code: string;
    customer_name: string;
    contact_number?: string | null;
    destination: string;
    purpose: string | null;
    booking_date: string;
    booking_date_label: string;
    booking_date_long?: string;
    start_time: string;
    start_time_label: string;
    passenger_count: number;
    status: string;
    status_label: string;
    report_status?: string;
    report_status_label?: string;
    remarks: string | null;
    payment_status?: string;
    payment_status_label?: string;
    payment_method?: string;
    gcash_reference?: string | null;
    amount?: number;
    amount_label?: string;
    quoted_amount?: number;
    quoted_amount_label?: string;
    verified_amount?: number;
    payment_verified_at_label?: string | null;
    payment_verified_by_name?: string | null;
    recorded_passengers?: ManifestPassenger[];
    boat: {
        id: number;
        name: string;
        code: string;
        capacity: number;
        operator_name: string | null;
    };
};

export type ReportBoatMaintenance = {
    id: number;
    date: string | null;
    date_label: string | null;
    type: string;
    description: string | null;
};

export type BoatUsageRow = {
    id: number;
    name: string;
    code: string;
    operator_name: string | null;
    capacity?: number;
    type?: string;
    status?: string;
    status_label?: string;
    report_status?: string;
    report_status_label?: string;
    picture_url?: string | null;
    registration_date_label?: string | null;
    registered_in_safesakay_label?: string | null;
    last_maintenance_label?: string | null;
    is_active?: boolean;
    regular_trips: number;
    private_bookings: number;
    completed_trips?: number;
    total_passengers?: number;
    regular_revenue?: number;
    rental_revenue?: number;
    total_revenue?: number;
    total_revenue_label?: string;
    total_trips: number;
    maintenance_records: number;
    in_maintenance: boolean;
    usage_share: number;
    usage_frequency: 'High' | 'Medium' | 'Low';
    maintenance?: ReportBoatMaintenance[];
    violation_count?: number;
    suspended?: boolean;
    suspension_start?: string | null;
    suspension_end?: string | null;
    violations?: Array<{
        id: number;
        date_label?: string | null;
        booking_code?: string | null;
        reason?: string | null;
        strike?: number;
    }>;
};
