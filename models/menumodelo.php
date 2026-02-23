<?php
// models/DashboardModel.php

class DashboardModel {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getCounters() {
        try {
            // 1. Total Estudiantes
            $totalEst = $this->pdo->query("SELECT COUNT(*) FROM public.estudiantes")->fetchColumn();

            // 2. Total Docentes que son Tutores
            $totalTut = $this->pdo->query("SELECT COUNT(*) FROM public.docentes WHERE es_tutor = TRUE")->fetchColumn();

            // 3. Total Pre-defensas Activas (PENDIENTE o Aprobado)
            $totalAsig = $this->pdo->query("SELECT COUNT(*) FROM public.pre_defensas WHERE estado != 'Rechazado'")->fetchColumn();

            return [
                'estudiantes' => $totalEst ?: 0,
                'tutores' => $totalTut ?: 0,
                'asignaciones' => $totalAsig ?: 0
            ];
        } catch (PDOException $e) {
            error_log("Error en DashboardModel: " . $e->getMessage());
            return ['estudiantes' => 0, 'tutores' => 0, 'asignaciones' => 0];
        }
    }
}