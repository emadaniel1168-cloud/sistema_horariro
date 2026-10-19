# Sistema de horarios

## Acceso de administrador

Al abrir la aplicación se muestra la pantalla de inicio de sesión. En la primera ejecución, la aplicación crea la tabla `admin` y una cuenta inicial. Si la base de datos ya tiene la tabla `administrador`, se renombra automáticamente a `admin` para conservar las cuentas:

- Correo: `administrador1234@gmail.com`
- Contraseña: `admin123`

Después de iniciar sesión, selecciona **Configuración de cuenta** al final del menú para cambiar el nombre, el correo o la contraseña. Para cambiar la contraseña, escribe y confirma la nueva clave; se almacena con hash. Se recomienda cambiar la contraseña inicial.
