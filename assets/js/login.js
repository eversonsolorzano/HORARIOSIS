// login.js - Animaciones adicionales para el login

document.addEventListener('DOMContentLoaded', function() {
    // Efecto de partículas en el fondo
    createParticles();
    
    // Efecto de entrada para los elementos del formulario
    animateFormElements();
    
    // Efecto hover avanzado para botones sociales
    setupSocialButtons();
});

function createParticles() {
    const container = document.querySelector('.background-animation');
    
    // Crear partículas adicionales
    for (let i = 0; i < 15; i++) {
        const particle = document.createElement('div');
        particle.classList.add('particle');
        
        // Tamaño aleatorio
        const size = Math.random() * 6 + 2;
        particle.style.width = `${size}px`;
        particle.style.height = `${size}px`;
        
        // Posición aleatoria
        particle.style.left = `${Math.random() * 100}%`;
        particle.style.top = `${Math.random() * 100}%`;
        
        // Animación aleatoria
        const duration = Math.random() * 20 + 10;
        const delay = Math.random() * 5;
        particle.style.animation = `floatParticle ${duration}s ${delay}s infinite linear`;
        
        // Opacidad aleatoria
        particle.style.opacity = Math.random() * 0.5 + 0.1;
        particle.style.background = 'rgba(255, 255, 255, 0.3)';
        particle.style.borderRadius = '50%';
        particle.style.position = 'absolute';
        
        container.appendChild(particle);
    }
    
    // Añadir la animación de partículas al CSS
    const style = document.createElement('style');
    style.textContent = `
        @keyframes floatParticle {
            0% {
                transform: translateY(0) translateX(0);
            }
            25% {
                transform: translateY(-20px) translateX(10px);
            }
            50% {
                transform: translateY(-40px) translateX(-10px);
            }
            75% {
                transform: translateY(-20px) translateX(-20px);
            }
            100% {
                transform: translateY(0) translateX(0);
            }
        }
    `;
    document.head.appendChild(style);
}

function animateFormElements() {
    const inputs = document.querySelectorAll('.animated-input');
    
    inputs.forEach((input, index) => {
        // Retraso escalonado para la animación de entrada
        input.style.animationDelay = `${index * 0.1}s`;
        input.style.animation = 'slideInRight 0.5s both';
    });
    
    // Añadir la animación al CSS
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
    `;
    document.head.appendChild(style);
}

function setupSocialButtons() {
    const socialButtons = document.querySelectorAll('.social-btn');
    
    socialButtons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            const icon = this.querySelector('i');
            if (icon) {
                icon.style.transform = 'scale(1.2)';
                icon.style.transition = 'transform 0.3s';
            }
        });
        
        button.addEventListener('mouseleave', function() {
            const icon = this.querySelector('i');
            if (icon) {
                icon.style.transform = 'scale(1)';
            }
        });
    });
}

// Función para simular el envío del formulario
function simulateLogin() {
    const btn = document.querySelector('.btn-login');
    const form = document.getElementById('loginForm');
    
    if (form.checkValidity()) {
        btn.classList.add('loading');
        
        // Simular una petición al servidor
        setTimeout(() => {
            btn.classList.remove('loading');
            
            // Aquí normalmente redirigirías al usuario
            // window.location.href = 'dashboard.php';
            
            // Mostrar mensaje de éxito (solo para demostración)
            const successMsg = document.createElement('div');
            successMsg.className = 'login-success';
            successMsg.innerHTML = `
                <i class="fas fa-check-circle"></i>
                <span>¡Inicio de sesión exitoso! Redirigiendo...</span>
            `;
            successMsg.style.cssText = `
                background: #c6f6d5;
                color: #22543d;
                padding: 15px;
                border-radius: 12px;
                margin-top: 20px;
                display: flex;
                align-items: center;
                border-left: 5px solid #38a169;
                animation: slideInUp 0.5s;
            `;
            
            const formSection = document.querySelector('.form-section');
            formSection.appendChild(successMsg);
            
            // Remover después de 3 segundos
            setTimeout(() => {
                successMsg.remove();
            }, 3000);
        }, 2000);
    }
}