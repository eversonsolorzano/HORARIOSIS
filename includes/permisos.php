<?php
require_once 'auth.php';

class Permisos {
    public static function esCoordinador() {
        return Auth::getUserData()['rol'] == ROL_COORDINADOR;
    }
    
    public static function esProfesor() {
        return Auth::getUserData()['rol'] == ROL_PROFESOR;
    }
    
    public static function esEstudiante() {
        return Auth::getUserData()['rol'] == ROL_ESTUDIANTE;
    }
    
    public static function puedeVer($modulo) {
        $rol = Auth::getUserData()['rol'];
        
        $permisos = [
            'coordinador' => ['usuarios', 'programas', 'estudiantes', 'profesores', 
                            'semestres', 'cursos', 'aulas', 'horarios', 'inscripciones', 
                            'cambios', 'notificaciones', 'configuracion', 'reportes'],
            'profesor' => ['mis_horarios', 'mis_cursos', 'mis_estudiantes', 'notificaciones'],
            'estudiante' => ['mi_horario', 'mis_cursos', 'inscribir', 'calificaciones', 'notificaciones']
        ];
        
        return in_array($modulo, $permisos[$rol]);
    }
}
?>