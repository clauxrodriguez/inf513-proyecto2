import Modal from '../ui/Modal';
import Alerta from '../ui/Alerta';

const campoClase = 'w-full border border-slate-300 rounded-lg px-3 py-1.5';

/** Alta o edición de un usuario: datos personales, roles (usuario_rol) y carreras (usuario_carrera). */
export default function ModalUsuario({ editandoId, form, setForm, carreras, roles, error, guardando, onClose, onSubmit }) {
  const esEstudiante = form.roles.includes('ESTUDIANTE');
  const alternar = (lista, valor) => (form[lista].includes(valor) ? form[lista].filter((v) => v !== valor) : [...form[lista], valor]);
  const campo = (etiqueta, clave, props = {}) => (
    <label className="block font-medium text-slate-700">{etiqueta}
      <input {...props} value={form[clave]} onChange={(e) => setForm({ ...form, [clave]: e.target.value })} className={`mt-1 ${campoClase}`} />
    </label>
  );

  return (
    <Modal titulo={editandoId ? 'Editar usuario' : 'Registrar nuevo usuario'}>
      <Alerta mensaje={error} className="mb-4 text-xs" />
      <form onSubmit={onSubmit} noValidate autoComplete="off" className="space-y-3 text-sm">
        <div className="grid grid-cols-2 gap-3">
          {campo('Nombres *', 'nombres', { maxLength: 100 })}
          {campo('Apellidos *', 'apellidos', { maxLength: 100 })}
        </div>
        <div className="grid grid-cols-2 gap-3">
          {campo('C.I. *', 'ci', { maxLength: 20 })}
          {esEstudiante ? campo('Registro Universitario *', 'registro', { maxLength: 20 }) : campo('Cargo institucional *', 'cargo', { maxLength: 120, placeholder: 'Ej.: Director de Carrera' })}
        </div>
        <fieldset>
          <legend className="mb-1 font-medium text-slate-700">Roles *</legend>
          <div className="grid grid-cols-2 gap-1.5">
            {roles.map((rol) => (
              <label key={rol.codigo} className="flex items-center gap-2 text-xs text-slate-700">
                <input type="checkbox" checked={form.roles.includes(rol.codigo)} onChange={() => setForm({ ...form, roles: alternar('roles', rol.codigo) })} />
                {rol.nombre}
              </label>
            ))}
          </div>
        </fieldset>
        <fieldset>
          <legend className="mb-1 font-medium text-slate-700">Carreras {form.roles.some((r) => ['ESTUDIANTE', 'DIRECTOR_CARRERA', 'DECANO'].includes(r)) ? '*' : '(opcional)'}</legend>
          <div className="grid max-h-32 grid-cols-2 gap-1.5 overflow-y-auto rounded-lg border border-slate-200 p-2">
            {carreras.map((carrera) => (
              <label key={carrera.id} className="flex items-center gap-2 text-xs text-slate-700">
                <input type="checkbox" checked={form.carreras.includes(carrera.id)} onChange={() => setForm({ ...form, carreras: alternar('carreras', carrera.id) })} />
                {carrera.nombre}
              </label>
            ))}
          </div>
        </fieldset>
        {campo('Correo institucional *', 'email', { type: 'email', maxLength: 150 })}
        <div className="grid grid-cols-2 gap-3">
          {campo('Teléfono', 'telefono', { maxLength: 30 })}
          {campo(`Contraseña ${editandoId ? '(vacío = mantener)' : '*'}`, 'password', { type: 'password', autoComplete: 'new-password', maxLength: 100 })}
        </div>
        <div className="flex justify-end gap-2 pt-3 border-t border-slate-200">
          <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancelar</button>
          <button type="submit" disabled={guardando} className="px-4 py-2 rounded-lg bg-blue-900 hover:bg-blue-800 text-white font-medium disabled:opacity-50">{guardando ? 'Guardando...' : 'Guardar'}</button>
        </div>
      </form>
    </Modal>
  );
}
