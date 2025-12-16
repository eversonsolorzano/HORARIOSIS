<?php
require_once 'database.php';
require_once 'session.php';

class Auth {
    public static function login($username, $password) {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT * FROM usuarios WHERE username = ? AND activo = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && $password === $user['password']) { // Sin hash en desarrollo
            Session::set('user_id', $user['id_usuario']);
            Session::set('username', $user['username']);
            Session::set('rol', $user['rol']);
            Session::set('email', $user['email']);
            Session::set('programa', $user['programa_estudio']);
            
            return true;
        }
        
        return false;
    }
    
    public static function logout() {
        Session::destroy();
        header('Location: ../login.php');
        exit();
    }
    
    public static function isLoggedIn() {
        return Session::get('user_id') !== null;
    }
    
    public static function getUserData() {
        return [
            'id' => Session::get('user_id'),
            'username' => Session::get('username'),
            'rol' => Session::get('rol'),
            'email' => Session::get('email'),
            'programa' => Session::get('programa')
        ];
    }
    
    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            header('Location: ../login.php');
            exit();
        }
    }
    
    public static function requireRole($role) {
        self::requireLogin();
        
        if (Session::get('rol') != $role) {
            header('Location: ../' . Session::get('rol') . '/dashboard.php');
            exit();
        }
    }
}
?>