<?php

function current_user_role(): string
{
    $role = $_SESSION['user_role'] ?? ($_SESSION['role_name'] ?? '');
    return trim((string) $role);
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        if (function_exists('request_expects_json') && request_expects_json()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Your session has expired. Please log in again.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Location: ' . base_url('auth/login.php'));
        exit;
    }
}

function require_role(string ...$allowedRoles): void
{
    require_login();

    $role = current_user_role();

    if (!in_array($role, $allowedRoles, true)) {
        if (function_exists('request_expects_json') && request_expects_json()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Access denied.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        set_flash('error', 'Access denied.');
        redirect('dashboard/index.php');
    }
}

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function user_has_any_role(string ...$roles): bool
{
    return in_array(current_user_role(), $roles, true);
}
