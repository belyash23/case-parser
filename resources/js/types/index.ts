export interface User {
    id: number;
    login: string | null;
    name: string;
    email: string;
    is_admin: boolean;
}

export interface SharedProps {
    name: string;
    auth: { user: User | null };
    flash: { success?: string; error?: string };
    [key: string]: unknown;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginator<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
}
