import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { cambiarRolActivo, cerrarSesion, iniciarSesion, obtenerSesion } from '../api/authApi';

const AuthContext = createContext();

const sesionVacia = { user: null, roles: [], permisos: [], menus: [] };

export function AuthProvider({ children }) {
  const [sesion, setSesion] = useState(sesionVacia);
  const [loading, setLoading] = useState(true);

  const aplicarSesion = (datos) => setSesion({
    user: datos.user,
    roles: datos.roles ?? [],
    permisos: datos.permisos ?? [],
    menus: datos.menus ?? [],
  });

  useEffect(() => {
    localStorage.removeItem('token');
    if (!sessionStorage.getItem('token')) {
      setLoading(false);
      return;
    }
    obtenerSesion()
      .then((res) => aplicarSesion(res.data))
      .catch(() => sessionStorage.removeItem('token'))
      .finally(() => setLoading(false));
  }, []);

  const login = async (email, password, rol = null) => {
    const res = await iniciarSesion(email, password, rol);
    sessionStorage.setItem('token', res.data.token);
    aplicarSesion(res.data);
    return res.data;
  };

  /** Cambia el rol activo: el backend devuelve el menú y los permisos del nuevo rol. */
  const cambiarRol = async (rol) => {
    const res = await cambiarRolActivo(rol);
    aplicarSesion(res.data);
    return res.data;
  };

  const logout = async () => {
    try {
      await cerrarSesion();
    } finally {
      sessionStorage.removeItem('token');
      setSesion(sesionVacia);
    }
  };

  const tienePermiso = useCallback((...codigos) => codigos.some((c) => sesion.permisos.includes(c)), [sesion.permisos]);

  return (
    <AuthContext.Provider value={{ ...sesion, login, logout, cambiarRol, tienePermiso, loading }}>
      {children}
    </AuthContext.Provider>
  );
}

export const useAuth = () => useContext(AuthContext);
