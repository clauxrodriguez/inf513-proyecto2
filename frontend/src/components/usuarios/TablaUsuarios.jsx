import { useState } from 'react';
import { Edit2, Power, Search, UserPlus } from 'lucide-react';

export default function TablaUsuarios({ usuarios, roles, onNuevo, onEditar, onToggleEstado }) {
  const [busqueda, setBusqueda] = useState('');
  const [filtroRol, setFiltroRol] = useState('');
  const nombreRol = (codigo) => roles.find((r) => r.codigo === codigo)?.nombre || codigo;
  const filtrados = usuarios.filter((u) => {
    const texto = `${u.nombres} ${u.apellidos} ${u.ci} ${u.email} ${u.registro || ''}`.toLowerCase();
    return texto.includes(busqueda.toLowerCase()) && (!filtroRol || u.roles.includes(filtroRol));
  });

  return (
    <>
      <div className="flex flex-wrap justify-between items-center gap-4">
        <div>
          <h1 className="text-xl font-bold text-slate-800">Usuarios y Roles</h1>
          <p className="text-sm text-slate-500">Cuentas institucionales y estudiantiles con sus roles y carreras</p>
        </div>
        <button type="button" onClick={onNuevo} className="flex items-center gap-2 bg-blue-900 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
          <UserPlus className="w-4 h-4" aria-hidden="true" />Nuevo usuario
        </button>
      </div>

      <div className="bg-white p-4 rounded-xl border border-slate-200 flex flex-wrap gap-3">
        <div className="relative flex-1 min-w-[240px]">
          <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" aria-hidden="true" />
          <label htmlFor="buscar-usuario" className="sr-only">Buscar usuario</label>
          <input id="buscar-usuario" type="search" placeholder="Buscar por nombre, C.I., registro o correo..." value={busqueda}
            onChange={(e) => setBusqueda(e.target.value)} className="w-full pl-9 pr-3 py-1.5 border border-slate-300 rounded-lg text-sm" />
        </div>
        <label htmlFor="filtro-rol" className="sr-only">Filtrar por rol</label>
        <select id="filtro-rol" value={filtroRol} onChange={(e) => setFiltroRol(e.target.value)} className="border border-slate-300 rounded-lg px-3 py-1.5 text-sm bg-white">
          <option value="">Todos los roles</option>
          {roles.map((rol) => <option key={rol.codigo} value={rol.codigo}>{rol.nombre}</option>)}
        </select>
      </div>

      <div className="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <table className="w-full text-sm text-left">
          <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 uppercase text-xs">
            <tr>
              <th className="px-4 py-3">Usuario</th><th className="px-4 py-3">C.I. / Registro</th><th className="px-4 py-3">Roles / Cargo</th>
              <th className="px-4 py-3">Carreras</th><th className="px-4 py-3">Estado</th><th className="px-4 py-3 text-right">Acciones</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {filtrados.map((u) => (
              <tr key={u.id} className="hover:bg-slate-50">
                <td className="px-4 py-3"><div className="font-medium text-slate-800">{u.nombres} {u.apellidos}</div><div className="text-xs text-slate-500">{u.email}</div></td>
                <td className="px-4 py-3 text-slate-600"><div>C.I. {u.ci}</div>{u.registro && <div className="text-xs text-slate-400">Reg. {u.registro}</div>}</td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap gap-1">{u.roles.map((r) => <span key={r} className="inline-block px-2 py-0.5 text-xs font-medium rounded bg-blue-50 text-blue-800">{nombreRol(r)}</span>)}</div>
                  {u.cargo && <div className="text-xs text-slate-500 mt-0.5">{u.cargo}</div>}
                </td>
                <td className="px-4 py-3 text-xs text-slate-600">{u.carreras_nombre || '—'}</td>
                <td className="px-4 py-3"><span className={`inline-block px-2 py-0.5 text-xs rounded-full font-medium ${u.activo ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`}>{u.activo ? 'Activo' : 'Inactivo'}</span></td>
                <td className="px-4 py-3 text-right space-x-2">
                  <button type="button" onClick={() => onEditar(u)} aria-label={`Editar ${u.nombres} ${u.apellidos}`} className="p-1.5 text-slate-600 hover:text-blue-800 hover:bg-blue-50 rounded"><Edit2 className="w-4 h-4" aria-hidden="true" /></button>
                  <button type="button" onClick={() => onToggleEstado(u)} aria-label={u.activo ? `Desactivar a ${u.nombres}` : `Activar a ${u.nombres}`}
                    className={`p-1.5 rounded ${u.activo ? 'text-red-600 hover:bg-red-50' : 'text-emerald-600 hover:bg-emerald-50'}`}><Power className="w-4 h-4" aria-hidden="true" /></button>
                </td>
              </tr>
            ))}
            {!filtrados.length && <tr><td colSpan="6" className="px-4 py-6 text-center text-sm text-slate-500">No se encontraron usuarios.</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  );
}
