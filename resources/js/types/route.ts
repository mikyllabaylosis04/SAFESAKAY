export type RouteTripType = 'regular_public' | 'private_booking';

export type OrganizationRouteRecord = {
    id: number;
    name: string;
    origin: string;
    destination: string;
    trip_type: RouteTripType;
    trip_type_label: string;
    is_active: boolean;
    status_label: string;
    rental_price?: number | null;
    rental_price_label?: string | null;
    regular_fare?: number | null;
    regular_fare_label?: string | null;
    student_fare?: number | null;
    student_fare_label?: string | null;
    senior_fare?: number | null;
    senior_fare_label?: string | null;
    discount_percent?: number | null;
    student_discount_percent?: number | null;
    senior_discount_percent?: number | null;
    down_payment_percent?: number | null;
    down_payment_percent_label?: string | null;
};
