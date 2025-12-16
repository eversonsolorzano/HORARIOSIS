<?php
class Funciones {
    public static function sanitizar($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::sanitizar($value);
            }
        } else {
            $data = trim($data);
            $data = stripslashes($data);
            $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        }
        return $data;
    }
    
    public static function validarEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
    
    public static function redireccionar($url, $mensaje = null, $tipo = 'success') {
        if ($mensaje) {
            Session::setFlash($mensaje, $tipo);
        }
        header("Location: $url");
        exit();
    }
    
    public static function obtenerProgramas() {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT * FROM programas_estudio WHERE activo = 1");
        return $stmt->fetchAll();
    }
    
    public static function formatearFecha($fecha, $formato = 'd/m/Y') {
        if (empty($fecha)) return '';
        $date = new DateTime($fecha);
        return $date->format($formato);
    }
    
    public static function formatearHora($hora) {
        if (empty($hora)) return '';
        return date('h:i A', strtotime($hora));
    }
}
?>