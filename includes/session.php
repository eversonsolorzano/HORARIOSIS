<?php
class Session {
    public static function set($key, $value) {
        $_SESSION[$key] = $value;
    }
    
    public static function get($key) {
        return isset($_SESSION[$key]) ? $_SESSION[$key] : null;
    }
    
    public static function delete($key) {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }
    
    public static function destroy() {
        session_destroy();
        $_SESSION = array();
    }
    
    public static function setFlash($message, $type = 'success') {
        self::set('flash_message', $message);
        self::set('flash_type', $type);
    }
    
    public static function getFlash() {
        $message = self::get('flash_message');
        $type = self::get('flash_type');
        
        self::delete('flash_message');
        self::delete('flash_type');
        
        return $message ? ['message' => $message, 'type' => $type] : null;
    }
}
?>