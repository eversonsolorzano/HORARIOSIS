-- Crear la base de datos con el nombre que necesitas
CREATE DATABASE IF NOT EXISTS horarios_instituto;
USE horarios_instituto;

-- 1. TABLA DE USUARIOS (para login y roles)
CREATE TABLE usuarios (
    id_usuario INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(100) NOT NULL, -- Sin hash como solicitaste (SOLO DESARROLLO)
    rol ENUM('coordinador', 'profesor', 'estudiante') NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    programa_estudio ENUM('Topografía', 'Arquitectura', 'Enfermería') DEFAULT NULL,
    activo BOOLEAN DEFAULT TRUE,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. TABLA DE PROGRAMAS DE ESTUDIO
CREATE TABLE programas_estudio (
    id_programa INT PRIMARY KEY AUTO_INCREMENT,
    codigo_programa VARCHAR(10) UNIQUE NOT NULL,
    nombre_programa VARCHAR(100) NOT NULL,
    duracion_semestres INT DEFAULT 6,
    descripcion TEXT,
    coordinador_id INT,
    activo BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (coordinador_id) REFERENCES usuarios(id_usuario)
);

-- 3. TABLA DE ESTUDIANTES
CREATE TABLE estudiantes (
    id_estudiante INT PRIMARY KEY AUTO_INCREMENT,
    id_usuario INT UNIQUE NOT NULL,
    id_programa INT NOT NULL,
    codigo_estudiante VARCHAR(20) UNIQUE NOT NULL,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    documento_identidad VARCHAR(20) UNIQUE NOT NULL,
    fecha_nacimiento DATE,
    genero ENUM('M', 'F', 'Otro'),
    telefono VARCHAR(15),
    direccion TEXT,
    semestre_actual INT DEFAULT 1, -- 1 a 6 semestres
    estado ENUM('activo', 'inactivo', 'graduado', 'retirado') DEFAULT 'activo',
    fecha_ingreso DATE NOT NULL,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
    FOREIGN KEY (id_programa) REFERENCES programas_estudio(id_programa),
    CHECK (semestre_actual BETWEEN 1 AND 6)
);

-- 4. TABLA DE PROFESORES
CREATE TABLE profesores (
    id_profesor INT PRIMARY KEY AUTO_INCREMENT,
    id_usuario INT UNIQUE NOT NULL,
    codigo_profesor VARCHAR(20) UNIQUE NOT NULL,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    documento_identidad VARCHAR(20) UNIQUE NOT NULL,
    especialidad VARCHAR(100) NOT NULL,
    titulo_academico VARCHAR(100),
    telefono VARCHAR(15),
    email_institucional VARCHAR(100),
    programas_dictados SET('Topografía', 'Arquitectura', 'Enfermería'), -- Puede dictar en varios programas
    activo BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE
);

-- 5. TABLA DE SEMESTRES ACADÉMICOS
CREATE TABLE semestres_academicos (
    id_semestre INT PRIMARY KEY AUTO_INCREMENT,
    codigo_semestre VARCHAR(20) UNIQUE NOT NULL, -- Ej: "2024-1", "2024-2"
    nombre_semestre VARCHAR(100) NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    estado ENUM('planificación', 'en_curso', 'finalizado') DEFAULT 'planificación'
);

-- 6. TABLA DE CURSOS/MATERIAS POR PROGRAMA Y SEMESTRE
CREATE TABLE cursos (
    id_curso INT PRIMARY KEY AUTO_INCREMENT,
    id_programa INT NOT NULL,
    codigo_curso VARCHAR(20) NOT NULL,
    nombre_curso VARCHAR(100) NOT NULL,
    descripcion TEXT,
    creditos INT DEFAULT 3,
    horas_semanales INT NOT NULL,
    semestre INT NOT NULL, -- 1 a 6 semestres
    tipo_curso ENUM('obligatorio', 'electivo', 'taller', 'laboratorio') DEFAULT 'obligatorio',
    prerrequisitos TEXT, -- IDs de cursos separados por comas
    activo BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (id_programa) REFERENCES programas_estudio(id_programa),
    UNIQUE KEY unique_curso_programa (codigo_curso, id_programa),
    CHECK (semestre BETWEEN 1 AND 6)
);

-- 7. TABLA DE AULAS
CREATE TABLE aulas (
    id_aula INT PRIMARY KEY AUTO_INCREMENT,
    codigo_aula VARCHAR(20) UNIQUE NOT NULL,
    nombre_aula VARCHAR(50) NOT NULL,
    capacidad INT NOT NULL,
    piso INT,
    edificio VARCHAR(50),
    tipo_aula ENUM('normal', 'laboratorio', 'taller', 'clínica', 'aula_especial') DEFAULT 'normal',
    equipamiento TEXT,
    programas_permitidos SET('Topografía', 'Arquitectura', 'Enfermería'), -- Aulas específicas por programa
    disponible BOOLEAN DEFAULT TRUE
);

-- 8. TABLA DE HORARIOS (CORE DEL SISTEMA) - NO CAMBIÉ EL NOMBRE DE ESTA TABLA
CREATE TABLE horarios (
    id_horario INT PRIMARY KEY AUTO_INCREMENT,
    id_curso INT NOT NULL,
    id_profesor INT NOT NULL,
    id_aula INT NOT NULL,
    id_semestre INT NOT NULL,
    dia_semana ENUM('Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado') NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    tipo_clase ENUM('teoría', 'práctica', 'laboratorio', 'taller') DEFAULT 'teoría',
    grupo VARCHAR(10), -- Ej: "A", "B", "C" para separar grupos del mismo curso
    capacidad_grupo INT,
    activo BOOLEAN DEFAULT TRUE,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    creado_por INT, -- ID del coordinador que creó el horario
    FOREIGN KEY (id_curso) REFERENCES cursos(id_curso),
    FOREIGN KEY (id_profesor) REFERENCES profesores(id_profesor),
    FOREIGN KEY (id_aula) REFERENCES aulas(id_aula),
    FOREIGN KEY (id_semestre) REFERENCES semestres_academicos(id_semestre),
    FOREIGN KEY (creado_por) REFERENCES usuarios(id_usuario),
    UNIQUE KEY unique_horario_aula (id_aula, dia_semana, hora_inicio, id_semestre),
    UNIQUE KEY unique_horario_profesor (id_profesor, dia_semana, hora_inicio, id_semestre),
    UNIQUE KEY unique_horario_curso_grupo (id_curso, dia_semana, hora_inicio, grupo, id_semestre),
    CHECK (hora_inicio < hora_fin)
);

-- 9. TABLA DE INSCRIPCIONES (Estudiantes en horarios específicos)
CREATE TABLE inscripciones (
    id_inscripcion INT PRIMARY KEY AUTO_INCREMENT,
    id_estudiante INT NOT NULL,
    id_horario INT NOT NULL,
    fecha_inscripcion DATE NOT NULL,
    estado ENUM('inscrito', 'retirado', 'aprobado', 'reprobado') DEFAULT 'inscrito',
    nota_final DECIMAL(4,2),
    FOREIGN KEY (id_estudiante) REFERENCES estudiantes(id_estudiante),
    FOREIGN KEY (id_horario) REFERENCES horarios(id_horario),
    UNIQUE KEY unique_inscripcion (id_estudiante, id_horario)
);

-- 10. TABLA DE CAMBIOS DE HORARIO (Registro de modificaciones)
CREATE TABLE cambios_horario (
    id_cambio INT PRIMARY KEY AUTO_INCREMENT,
    id_horario INT NOT NULL,
    tipo_cambio ENUM('profesor', 'aula', 'hora', 'dia', 'eliminado') NOT NULL,
    valor_anterior TEXT,
    valor_nuevo TEXT,
    fecha_cambio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    realizado_por INT NOT NULL, -- ID del coordinador
    motivo TEXT,
    FOREIGN KEY (id_horario) REFERENCES horarios(id_horario),
    FOREIGN KEY (realizado_por) REFERENCES usuarios(id_usuario)
);

-- 11. TABLA DE NOTIFICACIONES (Para actualizaciones en tiempo real)
CREATE TABLE notificaciones (
    id_notificacion INT PRIMARY KEY AUTO_INCREMENT,
    id_usuario INT NOT NULL,
    tipo_notificacion ENUM('cambio_horario', 'nuevo_curso', 'recordatorio', 'sistema') NOT NULL,
    titulo VARCHAR(200) NOT NULL,
    mensaje TEXT NOT NULL,
    leido BOOLEAN DEFAULT FALSE,
    fecha_notificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    link_accion VARCHAR(500), -- URL o acción a realizar
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
);

-- 12. TABLA DE CONFIGURACIÓN DEL SISTEMA
CREATE TABLE configuracion (
    id_config INT PRIMARY KEY AUTO_INCREMENT,
    clave VARCHAR(50) UNIQUE NOT NULL,
    valor TEXT NOT NULL,
    descripcion VARCHAR(200),
    categoria VARCHAR(50)
);

-- ÍNDICES PARA OPTIMIZACIÓN
CREATE INDEX idx_horarios_dia ON horarios(dia_semana);
CREATE INDEX idx_horarios_semestre ON horarios(id_semestre);
CREATE INDEX idx_estudiantes_programa ON estudiantes(id_programa);
CREATE INDEX idx_estudiantes_semestre ON estudiantes(semestre_actual);
CREATE INDEX idx_cursos_programa_semestre ON cursos(id_programa, semestre);
CREATE INDEX idx_inscripciones_estado ON inscripciones(estado);
CREATE INDEX idx_notificaciones_usuario ON notificaciones(id_usuario, leido);

-- DATOS INICIALES DE PRUEBA (OPCIONAL)
INSERT INTO usuarios (username, password, rol, email, programa_estudio) VALUES
('admin', 'admin123', 'coordinador', 'admin@instituto.edu', NULL),
('prof.juan', 'prof123', 'profesor', 'juan@instituto.edu', NULL),
('est.maria', 'est123', 'estudiante', 'maria@instituto.edu', 'Topografía');

INSERT INTO programas_estudio (codigo_programa, nombre_programa, coordinador_id) VALUES
('TOP-001', 'Topografía', 1),
('ARQ-001', 'Arquitectura', 1),
('ENF-001', 'Enfermería', 1);

INSERT INTO estudiantes (id_usuario, id_programa, codigo_estudiante, nombres, apellidos, documento_identidad, fecha_ingreso) VALUES
(3, 1, 'EST-2024001', 'María', 'González', '12345678', '2024-01-15');