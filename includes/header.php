<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/functions.php';

$pageTitle = $pageTitle ?? APP_NAME;
$sectionTitle = $sectionTitle ?? 'Inicio';
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(page_title($sectionTitle)) ?></title>
    <link rel="stylesheet" href="<?= e(APP_BASE) ?>/css/style.css?v=<?= (int) filemtime(dirname(__DIR__) . '/css/style.css') ?>-<?= (int) filesize(dirname(__DIR__) . '/css/style.css') ?>">
</head>
<body>
<div class="app">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="main">
        <header class="topbar">
            <button class="menu-toggle" type="button" data-menu-toggle aria-label="Abrir menú">☰</button>
            <div class="breadcrumb">
                <span>Administración</span>
                <span class="breadcrumb-divider" aria-hidden="true">/</span>
                <strong><?= e($sectionTitle) ?></strong>
            </div>
            <div class="topbar-tools">
                <span class="connection-status"><span class="status-indicator"></span>Base conectada</span>
                <span class="user-chip"><span class="user-avatar">AD</span>Administrador</span>
            </div>
        </header>
        <main class="content">
            <?php if ($flash): ?>
                <?php if (in_array(($flash['type'] ?? ''), ['occupied', 'popup'], true)): ?>
                    <div hidden data-popup-alert="<?= e($flash['message']) ?>" data-popup-title="<?= e(($flash['type'] ?? '') === 'occupied' ? 'Hora ocupada' : 'Aviso de horario') ?>"></div>
                <?php else: ?>
                    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
                <?php endif; ?>
            <?php endif; ?>
