import { createContext, useContext, useState, useEffect } from 'react';
import { cerrarSesion, iniciarSesion, obtenerSesion } from '../api/authApi';

const AuthContext = createContext();

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [menus, setMenus] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    localStorage.removeItem('token');
    const token = sessionStorage.getItem('token');
    if (token) {
      obtenerSesion()
        .then((res) => {
          setUser(res.data.user);
          setMenus(res.data.menus);
        })
        .catch(() => {
          sessionStorage.removeItem('token');
        })
        .finally(() => setLoading(false));
    } else {
      setLoading(false);
    }
  }, []);

  const login = async (email, password) => {
    const res = await iniciarSesion(email, password);
    sessionStorage.setItem('token', res.data.token);
    setUser(res.data.user);
    setMenus(res.data.menus);
    return res.data;
  };

  const logout = async () => {
    try {
      await cerrarSesion();
    } finally {
      sessionStorage.removeItem('token');
      setUser(null);
      setMenus([]);
    }
  };

  return (
    <AuthContext.Provider value={{ user, menus, login, logout, loading }}>
      {children}
    </AuthContext.Provider>
  );
}

export const useAuth = () => useContext(AuthContext);