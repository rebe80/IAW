#!/bin/bash

# Este script obtiene usuarios con uidNumber >= 1000 de /etc/passwd
# y genera un archivo LDIF para agregarlos a un directorio LDAP.

# Obtener usuarios con uidNumber >= 1000
# Excluye usuarios del sistema con UID bajo.
# El patrón "x:[1-9][0-9][0-9][0-9]:" busca UID's de 4 dígitos que empiezan por 1-9 (es decir, >= 1000)
grep "x:[1-9][0-9][0-9][0-9]:" /etc/passwd > tmp.txt

# Crear o reiniciar el archivo ldif para asegurar que esté vacío antes de añadir entradas
>tmp.ldif

# Recorrer el archivo tmp.txt con la lista de usuarios
while read linea
do
    # Mostrar la línea que vamos a procesar (útil para depuración)
    echo "Procesando línea: $linea"

    # Obtener datos de cada campo de la línea de /etc/passwd
    uid=$(echo "$linea" | cut -d: -f1) # Primer campo: nombre de usuario (uid)
    
    # Quinto campo: GECOS (nombre completo). Extrae lo que hay antes de la primera coma.
    nomComp=$(echo "$linea" | cut -d: -f5 | cut -d, -f1)
    
    # Elimina espacios en blanco al inicio y final de nomComp
    nomComp=$(echo "$nomComp" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')

    # Si nomComp está vacío después de la extracción/limpieza, usar el uid como nombre completo
    if [ -z "$nomComp" ]; then
        nomComp="$uid"
    fi

    # Convertir el nombre completo en un array de palabras para obtener nombre y apellido
    # Asegúrate de que las palabras no estén vacías si no hay nombre o apellido explícito
    nomArray=($nomComp)
    nom="${nomArray[0]}"
    if [ -z "$nom" ]; then # Si la primera palabra está vacía
        nom="$uid"
    fi
    
    # Si hay una segunda palabra, úsala como apellido; de lo contrario, usa el uid
    ape="${nomArray[1]}"
    if [ -z "$ape" ]; then
        ape="$uid"
    fi

    # Iniciales: primera letra del nombre + primera letra del apellido
    inic=$(echo "$nom" | cut -c 1)$(echo "$ape" | cut -c 1)
    
    uidNum=$(echo "$linea" | cut -d: -f3) # Tercer campo: UID numérico
    
    # Obtener el hash de la contraseña del usuario de /etc/shadow
    usrPass=$(grep "$uid:" /etc/shadow | cut -d: -f2)
    
    shell=$(echo "$linea" | cut -d: -f7) # Séptimo campo: shell de login
    homedir=$(echo "$linea" | cut -d: -f6) # Sexto campo: directorio home

    # Volcar datos al archivo LDIF (tmp.ldif)
    echo "dn: uid=$uid,ou=usuarios,dc=aso,dc=local" >> tmp.ldif
    echo "objectClass: inetOrgPerson" >> tmp.ldif
    echo "objectClass: posixAccount" >> tmp.ldif
    echo "objectClass: shadowAccount" >> tmp.ldif
    echo "uid: $uid" >> tmp.ldif
    echo "sn: $ape" >> tmp.ldif
    echo "givenName: $nom" >> tmp.ldif
    echo "cn: $nomComp" >> tmp.ldif
    echo "displayName: $nomComp" >> tmp.ldif
    echo "uidNumber: $uidNum" >> tmp.ldif
    echo "gidNumber: 10000" >> tmp.ldif # GID común para los usuarios
    echo "userPassword: {crypt}$usrPass" >> tmp.ldif # Asegúrate de usar el formato {crypt} si tu LDAP lo requiere para hashes de shadow
    echo "gecos: $nomComp" >> tmp.ldif
    echo "loginShell: $shell" >> tmp.ldif
    echo "homeDirectory: $homedir" >> tmp.ldif
    echo "shadowExpire: -1" >> tmp.ldif      # La cuenta nunca expira
    echo "shadowFlag: 0" >> tmp.ldif          # Sin fecha de expiración
    echo "shadowWarning: 7" >> tmp.ldif       # Advertencia 7 días antes de expiración (si aplica)
    echo "shadowMin: 8" >> tmp.ldif           # Días mínimos entre cambios de contraseña
    echo "shadowMax: 999999" >> tmp.ldif      # Días máximos entre cambios de contraseña
    echo "shadowLastChange: 10877" >> tmp.ldif # Fecha del último cambio de contraseña (días desde 1/1/1970)
    echo "mail: ${uid}@aso.local" >> tmp.ldif # Correo electrónico basado en el UID
    echo "postalCode: 29000" >> tmp.ldif      # Código postal genérico
    echo "o: aso" >> tmp.ldif                 # Organización
    echo "initials: $inic" >> tmp.ldif        # Iniciales
    echo >> tmp.ldif # Línea en blanco para separar entradas LDIF
done < tmp.txt

# Añadimos los nuevos usuarios a LDAP
# La opción '-c' (continue) permite que la operación siga incluso si hay errores
# como entradas ya existentes (error 68).
echo "Intentando añadir usuarios a LDAP..."
ldapadd -x -c -D cn=admin,dc=aso,dc=local -W -f tmp.ldif

# Limpiar archivos temporales
echo "Limpiando archivos temporales..."
rm tmp.txt
rm tmp.ldif

echo "Proceso completado. Revisa la salida de ldapadd para posibles errores o advertencias."
