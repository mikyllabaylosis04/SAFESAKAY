export type NotificationGroup =
    | 'announcements'
    | 'rentals'
    | 'boats'
    | 'organizations'
    | 'weather'
    | 'activity';
export type NotificationTone = 'general' | 'delay' | 'rental' | 'maintenance' | 'schedule';

export type AdminNotice = {
    id: string;
    type: string;
    tone: NotificationTone;
    group: NotificationGroup;
    type_label: string;
    title: string;
    body: string;
    subtitle?: string | null;
    footer?: string | null;
    href: string;
    unread: boolean;
    occurred_at: string;
    date_label: string;
    time_label: string;
};

export type AdminNoticeFeed = {
    items: AdminNotice[];
    unread_count: number;
    counts: {
        all: number;
        announcements: number;
        rentals: number;
        boats: number;
        organizations?: number;
        weather?: number;
        activity?: number;
    };
};
