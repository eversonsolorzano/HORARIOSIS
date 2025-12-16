// loaders.js - Manejo de cargadores tecnológicos

// Función para mostrar cargador según el rol
function showRoleLoader(role, userName) {
    const overlay = document.getElementById('loaderOverlay');
    const container = overlay.querySelector('.loader-container');
    
    // Determinar configuración según el rol
    let config;
    switch(role) {
        case 'coordinador':
            config = {
                title: 'ACCESO COORDINADOR',
                subtitle: 'Inicializando panel de control administrativo',
                color: '#00ff88',
                icon: 'fa-user-shield',
                messages: [
                    'Verificando credenciales de administrador...',
                    'Conectando a base de datos central...',
                    'Cargando módulos de administración...',
                    'Inicializando panel de control...'
                ]
            };
            break;
        case 'profesor':
            config = {
                title: 'ACCESO PROFESOR',
                subtitle: 'Cargando herramientas de gestión académica',
                color: '#ff9900',
                icon: 'fa-chalkboard-teacher',
                messages: [
                    'Validando perfil docente...',
                    'Sincronizando horarios de clases...',
                    'Cargando listas de estudiantes...',
                    'Preparando herramientas de evaluación...'
                ]
            };
            break;
        case 'estudiante':
            config = {
                title: 'ACCESO ESTUDIANTE',
                subtitle: 'Preparando entorno de aprendizaje personalizado',
                color: '#9d4edd',
                icon: 'fa-user-graduate',
                messages: [
                    'Verificando matrícula activa...',
                    'Cargando horario personal...',
                    'Sincronizando materiales de estudio...',
                    'Inicializando panel estudiantil...'
                ]
            };
            break;
        default:
            config = {
                title: 'ACCESO AL SISTEMA',
                subtitle: 'Inicializando entorno...',
                color: '#00ccff',
                icon: 'fa-user',
                messages: [
                    'Verificando credenciales...',
                    'Conectando al servidor...',
                    'Cargando configuración...',
                    'Redirigiendo al dashboard...'
                ]
            };
    }
    
    // Crear HTML del cargador
    container.innerHTML = createLoaderHTML(role, config, userName);
    
    // Mostrar overlay
    overlay.classList.add('active');
    
    // Iniciar animación de progreso
    startProgressAnimation(role);
    
    // Animar mensajes secuencialmente
    animateStatusMessages();
}

// Crear HTML del cargador
function createLoaderHTML(role, config, userName) {
    let visualHTML = '';
    
    // HTML visual según el rol
    if (role === 'coordinador') {
        visualHTML = `
            <div class="hologram-core"></div>
            <div class="hologram-ring"></div>
            <div class="hologram-data">
                <div class="data-node"></div>
                <div class="data-node"></div>
                <div class="data-node"></div>
                <div class="data-node"></div>
                <div class="data-connection">
                    <div class="connection-line"></div>
                </div>
            </div>
        `;
    } else if (role === 'profesor') {
        visualHTML = `
            <div class="circuit-board">
                <div class="circuit-line horizontal"></div>
                <div class="circuit-line horizontal"></div>
                <div class="circuit-line horizontal"></div>
                <div class="circuit-line vertical"></div>
                <div class="circuit-line vertical"></div>
                <div class="circuit-line vertical"></div>
                <div class="circuit-node"></div>
                <div class="circuit-node"></div>
                <div class="circuit-node"></div>
                <div class="circuit-node"></div>
                <div class="circuit-node"></div>
                <div class="circuit-node"></div>
                <div class="circuit-node"></div>
                <div class="circuit-node"></div>
                <div class="circuit-node"></div>
                <div class="pulse-wave"></div>
            </div>
        `;
    } else {
        visualHTML = `
            <div class="neural-network">
                <div class="neuron"></div>
                <div class="neuron"></div>
                <div class="neuron"></div>
                <div class="neuron"></div>
                <div class="neuron"></div>
                <div class="neuron"></div>
                <div class="neural-connection connection-1"></div>
                <div class="neural-connection connection-2"></div>
                <div class="neural-connection connection-3"></div>
                <div class="neural-connection connection-4"></div>
                <div class="neural-connection connection-5"></div>
                <div class="data-flow"></div>
            </div>
        `;
    }
    
    // Generar mensajes de estado
    const messagesHTML = config.messages.map((msg, index) => `
        <div class="status-item">
            <div class="status-icon">
                <i class="fas fa-circle"></i>
            </div>
            <div class="status-text">${msg}</div>
        </div>
    `).join('');
    
    // Nombre del usuario (si está disponible)
    let userInfoHTML = '';
    if (userName) {
        const roleNames = {
            'coordinador': 'Coordinador',
            'profesor': 'Profesor',
            'estudiante': 'Estudiante'
        };
        
        userInfoHTML = `
            <div class="user-info">
                <div class="user-avatar">
                    <i class="fas ${config.icon}"></i>
                </div>
                <div class="user-name">${userName}</div>
                <div class="user-role-badge">${roleNames[role] || 'Usuario'}</div>
            </div>
        `;
    }
    
    return `
        <div class="role-loader ${role}-loader">
            <h2 class="loader-title">${config.title}</h2>
            <p class="loader-subtitle">${config.subtitle}</p>
            
            <div class="loader-visual">
                ${visualHTML}
            </div>
            
            <div class="loader-progress">
                <div class="progress-bar" id="progressBar"></div>
            </div>
            
            <div class="progress-text">
                <span>Inicializando sistema</span>
                <span class="progress-percentage" id="progressPercentage">0%</span>
            </div>
            
            <div class="status-messages" id="statusMessages">
                ${messagesHTML}
            </div>
            
            ${userInfoHTML}
        </div>
    `;
}

// Animar barra de progreso
function startProgressAnimation(role) {
    const progressBar = document.getElementById('progressBar');
    const progressPercentage = document.getElementById('progressPercentage');
    
    let progress = 0;
    const interval = setInterval(() => {
        progress += Math.random() * 10;
        
        if (progress > 100) {
            progress = 100;
            clearInterval(interval);
            
            // Cambiar mensaje final
            progressPercentage.textContent = '100%';
            progressBar.style.width = '100%';
            
            // Mostrar mensaje de finalización
            setTimeout(() => {
                const statusMessages = document.getElementById('statusMessages');
                statusMessages.innerHTML += `
                    <div class="status-item">
                        <div class="status-icon" style="background: rgba(0, 255, 0, 0.2); color: #00ff00;">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="status-text" style="color: #00ff00;">
                            ¡Acceso concedido! Redirigiendo...
                        </div>
                    </div>
                `;
            }, 500);
        } else {
            progressBar.style.width = `${progress}%`;
            progressPercentage.textContent = `${Math.floor(progress)}%`;
        }
    }, 100);
}

// Animar mensajes secuencialmente
function animateStatusMessages() {
    const statusItems = document.querySelectorAll('.status-item');
    
    statusItems.forEach((item, index) => {
        setTimeout(() => {
            item.style.opacity = '1';
            item.style.transform = 'translateX(0)';
            
            // Marcar como completado
            setTimeout(() => {
                const icon = item.querySelector('.status-icon');
                icon.innerHTML = '<i class="fas fa-check"></i>';
                icon.style.background = 'rgba(0, 255, 0, 0.2)';
                icon.style.color = '#00ff00';
            }, 800);
        }, index * 1000 + 500);
    });
}

// Función para previsualizar cargador (para testing)
function previewLoader(role) {
    const testUsers = {
        'coordinador': 'Administrador Principal',
        'profesor': 'Prof. Juan Pérez',
        'estudiante': 'María González'
    };
    
    showRoleLoader(role, testUsers[role]);
}

// Detectar y mostrar cargador automáticamente si hay datos en sessionStorage
document.addEventListener('DOMContentLoaded', function() {
    const role = sessionStorage.getItem('previewRole');
    if (role) {
        const testUsers = {
            'coordinador': 'Administrador Principal',
            'profesor': 'Prof. Juan Pérez',
            'estudiante': 'María González'
        };
        previewLoader(role);
        sessionStorage.removeItem('previewRole');
    }
});