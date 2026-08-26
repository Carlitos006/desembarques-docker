<?php

declare(strict_types=1);

require_once __DIR__ . '/i18n.php';

/** @return array<string,string> */
function operationalEnglishTranslations(): array
{
    static $translations = null;
    if (is_array($translations)) {
        return $translations;
    }

    $translations = [
        'Método no permitido.' => 'Method not allowed.',
        'La sesión ha expirado.' => 'Your session has expired.',
        'La sesión de seguridad expiró. Recarga la página e intenta nuevamente.' => 'The security session has expired. Reload the page and try again.',
        'El token de seguridad no es válido.' => 'The security token is not valid.',
        'El token de seguridad no es válido. Recarga la página e inténtalo de nuevo.' => 'The security token is not valid. Reload the page and try again.',
        'La solicitud no contiene JSON válido.' => 'The request does not contain valid JSON.',
        'El desembarque indicado no es válido.' => 'The selected unloading record is not valid.',
        'El expediente indicado no es válido.' => 'The selected case file is not valid.',
        'Expediente no disponible.' => 'Case file unavailable.',
        'No tienes acceso a este desembarque.' => 'You do not have access to this unloading record.',
        'No tienes permisos para modificar el aviso de desembarque.' => 'You do not have permission to modify the unloading notice.',
        'La versión indicada no es válida.' => 'The selected version is not valid.',
        'No se encontró la versión solicitada.' => 'The requested version was not found.',
        'El PDF histórico ya no está disponible en el almacenamiento privado.' => 'The historical PDF is no longer available in private storage.',
        'La verificación de integridad del PDF histórico falló.' => 'Historical PDF integrity verification failed.',
        'La fotografía indicada no es válida.' => 'The selected photo is not valid.',
        'No se encontró la fotografía.' => 'The photo was not found.',
        'El archivo seleccionado no es una fotografía compatible con el anexo.' => 'The selected file is not a photo format supported by the annex.',
        'La fotografía ya no está disponible en el almacenamiento.' => 'The photo is no longer available in storage.',
        'Selecciona al menos una fotografía.' => 'Select at least one photo.',
        'Puedes cargar hasta 20 fotografías por operación.' => 'You can upload up to 20 photos per operation.',
        'El expediente dejó de estar disponible.' => 'The case file is no longer available.',
        'El expediente ya no está disponible para edición.' => 'The case file is no longer available for editing.',
        'No fue posible calcular la huella de una fotografía.' => 'A photo checksum could not be calculated.',
        'No fue posible guardar las fotografías en el expediente.' => 'The photos could not be saved to the case file.',
        'La fotografía se añadió correctamente al expediente.' => 'The photo was added to the case file.',
        'La fotografía no existe o ya fue eliminada.' => 'The photo does not exist or has already been deleted.',
        'La fotografía cambió mientras se procesaba la solicitud.' => 'The photo changed while the request was being processed.',
        'No fue posible retirar el archivo de la fotografía.' => 'The photo file could not be removed.',
        'No fue posible registrar la auditoría de la fotografía.' => 'The photo audit event could not be recorded.',
        'No fue posible eliminar la fotografía del expediente.' => 'The photo could not be deleted from the case file.',
        'La fotografía fue eliminada del expediente.' => 'The photo was deleted from the case file.',
        'El perfil seleccionado no es válido.' => 'The selected profile is not valid.',
        'El perfil seleccionado no está disponible para este desembarque.' => 'The selected profile is not available for this unloading record.',
        'No fue posible guardar el perfil.' => 'The profile could not be saved.',
        'No fue posible guardar un perfil por cliente.' => 'The client profile could not be saved.',
        'El aviso está cancelado. Reábrelo a Borrador antes de modificar sus datos o emitir una nueva versión.' => 'The notice is cancelled. Reopen it as Draft before modifying its data or issuing a new version.',
        'El aviso está cancelado. Reábrelo a Borrador antes de emitir una nueva versión.' => 'The notice is cancelled. Reopen it as Draft before issuing a new version.',
        'El anexo fotográfico no puede contener más de 100 imágenes.' => 'The photo annex cannot contain more than 100 images.',
        'Una de las fotografías del anexo no es válida.' => 'One of the photos in the annex is not valid.',
        'Una fotografía seleccionada ya no existe o no pertenece a este desembarque.' => 'A selected photo no longer exists or does not belong to this unloading record.',
        'No fue posible guardar la información del aviso.' => 'The notice information could not be saved.',
        'Información del Excel, aviso y anexo fotográfico guardada correctamente.' => 'Excel, notice, and photo annex information was saved successfully.',
        'El número de páginas del PDF no es válido.' => 'The PDF page count is not valid.',
        'No se recibió el PDF generado.' => 'The generated PDF was not received.',
        'El archivo recibido no es un PDF válido.' => 'The received file is not a valid PDF.',
        'No fue posible calcular la huella SHA-256 del PDF.' => 'The PDF SHA-256 checksum could not be calculated.',
        'El PDF se archivó como una nueva versión inmutable del aviso.' => 'The PDF was archived as a new immutable notice version.',
        'El PDF se generó, pero no fue posible archivarlo en el historial de versiones.' => 'The PDF was generated but could not be archived in the version history.',
        'El estado documental del aviso se actualizó correctamente.' => 'The notice document status was updated successfully.',
        'No fue posible actualizar el estado del aviso.' => 'The notice status could not be updated.',
        'El nuevo estado indicado no es válido.' => 'The selected new status is not valid.',
        'El movimiento indicado no es válido.' => 'The selected movement is not valid.',
        'El tipo de cierre documental no es válido.' => 'The documentary closure type is not valid.',
        'Para un cierre parcial, indica qué pedimentos o documentos están pendientes (mínimo 5 caracteres).' => 'For a partial closure, specify which customs entries or documents are pending (at least 5 characters).',
        'Una mercancía no puede repetirse al completar el cierre.' => 'A merchandise line cannot be repeated when completing the closure.',
        'Cada mercancía debe conservar al menos un dato aduanal antes de completar el cierre.' => 'Each merchandise line must contain at least one customs field before the closure can be completed.',
        'No se recibieron datos aduanales para completar el cierre.' => 'No customs information was received to complete the closure.',
        'No se puede completar un movimiento anulado.' => 'A voided movement cannot be completed.',
        'Este movimiento ya tiene un cierre documental completo.' => 'This movement already has a complete documentary closure.',
        'Los renglones del movimiento cambiaron. Recarga el expediente e inténtalo de nuevo.' => 'The movement lines changed. Reload the case file and try again.',
        'Faltan datos de una mercancía del movimiento. Recarga el expediente.' => 'Customs information is missing for one of the movement lines. Reload the case file.',
        'El cierre cambió mientras se procesaba. Recarga el expediente.' => 'The closure changed while it was being processed. Reload the case file.',
        'Los pedimentos se completaron sin alterar las cantidades de la salida.' => 'The customs information was completed without changing the departure quantities.',
        'No fue posible completar el cierre documental.' => 'The documentary closure could not be completed.',
        'Indica el motivo de la anulación (mínimo 5 caracteres).' => 'Enter the void reason (at least 5 characters).',
        'El movimiento de salida no existe en este expediente.' => 'The outbound movement does not exist in this case file.',
        'El movimiento cambió mientras se procesaba la anulación. Recarga el expediente.' => 'The movement changed while the void was being processed. Reload the case file.',
        'El movimiento fue anulado. Las cantidades regresaron al saldo disponible.' => 'The movement was voided. The quantities were returned to the available balance.',
        'No fue posible anular el movimiento.' => 'The movement could not be voided.',
        'Debes indicar una fecha de salida válida.' => 'You must enter a valid departure date.',
        'Selecciona al menos una mercancía.' => 'Select at least one merchandise line.',
        'Selecciona al menos una mercancía para el Alcance.' => 'Select at least one merchandise line for the addendum.',
        'Selecciona al menos una mercancía e indica la cantidad que sale.' => 'Select at least one merchandise line and enter the outgoing quantity.',
        'Una mercancía no puede repetirse dentro de la misma salida.' => 'A merchandise line cannot be repeated within the same departure.',
        'Captura al menos uno de los datos aduanales para la mercancía' => 'Enter at least one customs data field for the merchandise line',
        'No fue posible registrar la salida de mercancía.' => 'The merchandise departure could not be recorded.',
        'La salida de mercancía se registró correctamente.' => 'The merchandise departure was recorded successfully.',
        'La salida se registró con cierre documental parcial. Podrás completar los pedimentos desde el historial.' => 'The departure was recorded with a partial documentary closure. You can complete the customs information from the history.',
        'El Alcance indicado no es válido.' => 'The selected addendum is not valid.',
        'Indica una fecha válida para el Alcance.' => 'Enter a valid date for the addendum.',
        'No se recibió el PDF del Alcance.' => 'The addendum PDF was not received.',
        'El documento generado no es un PDF válido.' => 'The generated document is not a valid PDF.',
        'No fue posible calcular la huella del PDF del Alcance.' => 'The addendum PDF checksum could not be calculated.',
        'El Aviso está cancelado. Reábrelo antes de generar un Alcance.' => 'The notice is cancelled. Reopen it before generating an addendum.',
        'El Alcance se generó y archivó correctamente.' => 'The addendum was generated and archived successfully.',
        'No fue posible guardar el Alcance.' => 'The addendum could not be saved.',
        'No se encontró el Alcance.' => 'The addendum was not found.',
        'El Alcance está cancelado y no puede emitir una nueva versión.' => 'A new version cannot be issued for the cancelled addendum.',
        'La nueva versión del Alcance se archivó correctamente.' => 'The new addendum version was archived successfully.',
        'No fue posible archivar la nueva versión del Alcance.' => 'The new addendum version could not be archived.',
        'La versión del Alcance indicada no es válida.' => 'The selected addendum version is not valid.',
        'No se encontró la versión del Alcance.' => 'The addendum version was not found.',
        'El PDF del Alcance ya no está disponible.' => 'The addendum PDF is no longer available.',
        'La verificación de integridad del PDF del Alcance falló.' => 'Addendum PDF integrity verification failed.',
        'Captura una fecha/hora de recibido válida.' => 'Enter a valid date and time received.',
        'Captura el folio del acuse.' => 'Enter the acknowledgment reference number.',
        'La evidencia del acuse no pudo procesarse: ' => 'The acknowledgment evidence could not be processed: ',
        'El Alcance todavía no tiene una versión PDF emitida.' => 'The addendum does not yet have an issued PDF version.',
        'Indica el motivo de la corrección del acuse existente.' => 'Enter the reason for correcting the existing acknowledgment.',
        'No fue posible calcular la huella de la evidencia.' => 'The evidence checksum could not be calculated.',
        'El acuse del Alcance se corrigió correctamente.' => 'The addendum acknowledgment was corrected successfully.',
        'El acuse del Alcance se registró correctamente.' => 'The addendum acknowledgment was recorded successfully.',
        'No fue posible registrar el acuse del Alcance.' => 'The addendum acknowledgment could not be recorded.',
        'Selecciona uno o más avisos revisados para importar.' => 'Select one or more reviewed notices to import.',
        'No se encontró el lote histórico.' => 'The historical batch was not found.',
        'El lote indicado no es válido.' => 'The selected batch is not valid.',
        'No se encontró el lote de importación.' => 'The import batch was not found.',
        'La fila de revisión indicada no es válida.' => 'The selected review row is not valid.',
        'No se encontró el aviso dentro del lote.' => 'The notice was not found in the batch.',
        'Este aviso ya fue procesado y no puede volver a revisarse.' => 'This notice has already been processed and cannot be reviewed again.',
        'No fue posible guardar la revisión del aviso.' => 'The notice review could not be saved.',
        'Revisión guardada. El aviso está listo para importarse.' => 'Review saved. The notice is ready to import.',
        'La revisión se guardó, pero todavía faltan datos antes de importar.' => 'The review was saved, but information is still missing before import.',
        'Selecciona el cliente al que pertenecen estos avisos históricos.' => 'Select the client that owns these historical notices.',
        'El estado operativo seleccionado no es válido.' => 'The selected operational status is not valid.',
        'Selecciona al menos un PDF o Word (.doc/.docx).' => 'Select at least one PDF or Word file (.doc/.docx).',
        'El cliente seleccionado no existe.' => 'The selected client does not exist.',
        'El estado operativo seleccionado no está disponible.' => 'The selected operational status is not available.',
        'No existe un estado operativo activo para registrar los avisos históricos.' => 'There is no active operational status available for historical notices.',
        'La importación histórica acepta únicamente PDF, DOC y DOCX.' => 'Historical import accepts PDF, DOC, and DOCX files only.',
        'No fue posible validar uno de los archivos cargados.' => 'One of the uploaded files could not be validated.',
        'El Word compañero fue recibido, pero no produjo texto utilizable.' => 'The companion Word file was received but did not produce usable text.',
        'Se encontró el Word, pero falta el PDF final que debe conservarse como documento histórico original.' => 'The Word file was found, but the final PDF required as the original historical document is missing.',
        'No se pudo obtener texto utilizable.' => 'Usable text could not be extracted.',
        'No fue posible analizar el lote histórico.' => 'The historical batch could not be analyzed.',
        'Lote analizado. Revisa los resultados antes de importar registros definitivos.' => 'Batch analyzed. Review the results before importing permanent records.',
        'No fue posible ejecutar la validación 5E5 del lote.' => 'The batch 5E5 validation could not be executed.',
        'Validación 5E5 completada sin errores de integridad.' => '5E5 validation completed without integrity errors.',
        'La validación 5E5 encontró inconsistencias que deben revisarse.' => '5E5 validation found inconsistencies that must be reviewed.',
        'No existe un expediente destino para vincular el PDF.' => 'There is no destination case file to link the PDF.',
        'Ese PDF exacto ya está archivado; no puede vincularse como otra versión idéntica.' => 'That exact PDF is already archived and cannot be linked as another identical version.',
        'Falta: Número de aviso/manifiesto.' => 'Missing: notice/manifest number.',
        'Falta: Código MADE.' => 'Missing: MADE code.',
        'Falta: Fecha del oficio.' => 'Missing: document date.',
        'Falta: Rig.' => 'Missing: rig.',
        'Falta: IMO del Rig.' => 'Missing: rig IMO.',
        'Falta: Campo.' => 'Missing: field.',
        'Falta: Comitente.' => 'Missing: principal.',
        'Falta: Medio de transporte.' => 'Missing: means of transport.',
        'Falta: IMO del transporte.' => 'Missing: transport IMO.',
        'Falta: Consignataria.' => 'Missing: shipping agent.',
        'Falta: Fecha de embarque.' => 'Missing: departure date.',
        'Falta: Fecha de desembarque / ETA.' => 'Missing: unloading date / ETA.',
        'Debe existir al menos un pedimento.' => 'At least one customs entry is required.',
        'Debe existir al menos un renglón de mercancía.' => 'At least one merchandise line is required.',
        'El estado Presentado requiere fecha/hora efectiva.' => 'Presented status requires an effective date and time.',
        'El estado seleccionado requiere un motivo.' => 'The selected status requires a reason.',
        'No se importó ningún aviso del grupo seleccionado. Corrige la revisión e intenta nuevamente.' => 'None of the notices in the selected group were imported. Correct the review data and try again.',
        'Importación histórica completada. Los PDFs originales quedaron archivados como versiones inmutables.' => 'Historical import completed. The original PDFs were archived as immutable versions.',
        'El mismo PDF pertenece a un expediente eliminado. Debe restaurarse; no se creará otro.' => 'The same PDF belongs to a deleted case file. It must be restored; another record will not be created.',
        'El mismo PDF ya existe en el historial de emisiones.' => 'The same PDF already exists in the issuance history.',
        'Ya existe un aviso eliminado con el mismo número para este cliente. Debe restaurarse; no se creará otro.' => 'A deleted notice with the same number already exists for this client. It must be restored; another record will not be created.',
        'Ya existe un aviso eliminado con el mismo número para este cliente. Puede restaurarse o un administrador puede autorizar la importación como un aviso nuevo.' => 'A deleted notice with the same number already exists for this client. It can be restored, or an administrator can authorize importing it as a new notice.',
        'Ya existe un aviso eliminado con el mismo número para este cliente. Un administrador puede restaurarlo o un usuario interno puede importarlo como un aviso nuevo.' => 'A deleted notice with the same number already exists for this client. An administrator can restore it, or an internal user can import it as a new notice.',
        'Ya existe un aviso con el mismo número para este cliente.' => 'A notice with the same number already exists for this client.',
        'No fue posible preparar el almacenamiento temporal de importaciones.' => 'Temporary import storage could not be prepared.',
        'El almacenamiento temporal de importaciones no tiene permisos de escritura.' => 'Temporary import storage is not writable.',
        'Identificador de lote inválido.' => 'Invalid batch identifier.',
        'No fue posible crear el directorio del lote.' => 'The batch directory could not be created.',
        'El PDF histórico temporal ya no está disponible.' => 'The temporary historical PDF is no longer available.',
        'El archivo histórico temporal ya no es un PDF válido.' => 'The temporary historical file is no longer a valid PDF.',
        'La huella del PDF histórico cambió desde la etapa de revisión.' => 'The historical PDF checksum changed after the review stage.',
        'No fue posible archivar el PDF histórico en el almacenamiento definitivo.' => 'The historical PDF could not be archived in permanent storage.',
        'El lote dejó de estar disponible.' => 'The batch is no longer available.',
        'El cliente del lote dejó de estar disponible.' => 'The batch client is no longer available.',
        'El lote no tiene un estado operativo válido.' => 'The batch does not have a valid operational status.',
        'Una o más filas seleccionadas ya no pertenecen a este lote.' => 'One or more selected rows no longer belong to this batch.',
        'Existe un expediente eliminado para este aviso. Un administrador debe restaurarlo antes de continuar; no se creará otro.' => 'A deleted case file exists for this notice. An administrator must restore it before continuing; another record will not be created.',
        'Existe un expediente eliminado para este aviso. Debe restaurarse o un administrador debe autorizar la importación como un aviso nuevo.' => 'A deleted case file exists for this notice. It must be restored, or an administrator must authorize importing it as a new notice.',
        'Sólo un administrador puede autorizar un aviso nuevo cuando existe otro eliminado con el mismo número.' => 'Only an administrator can authorize a new notice when another notice with the same number has been deleted.',
        'Sólo un usuario interno puede autorizar un aviso nuevo cuando existe otro eliminado con el mismo número.' => 'Only an internal user can authorize a new notice when another notice with the same number has been deleted.',
        'La excepción administrativa ya no es válida. Recarga el lote y revisa nuevamente el duplicado.' => 'The administrative exception is no longer valid. Reload the batch and review the duplicate again.',
        'La excepción administrativa sólo puede usarse para un aviso eliminado con el mismo número.' => 'The administrative exception can only be used for a deleted notice with the same number.',
        'Indica el motivo para importar un aviso nuevo conservando el expediente eliminado.' => 'Enter the reason for importing a new notice while retaining the deleted case file.',
        'Sólo un administrador puede autorizar la importación sobre un aviso eliminado.' => 'Only an administrator can authorize an import when a deleted notice already exists.',
        'Sólo un usuario interno puede autorizar la importación sobre un aviso eliminado.' => 'Only an internal user can authorize an import when a deleted notice already exists.',
        'Existe un expediente eliminado para este aviso. Debe restaurarse o un usuario interno debe autorizar la importación como un aviso nuevo.' => 'A deleted case file exists for this notice. It must be restored, or an internal user must authorize importing it as a new notice.',
        'La excepción administrativa no corresponde a un aviso eliminado válido.' => 'The administrative exception does not correspond to a valid deleted notice.',
        'La excepción administrativa requiere un motivo.' => 'The administrative exception requires a reason.',
        'El expediente duplicado ya no está eliminado. Recarga el lote antes de continuar.' => 'The duplicate case file is no longer deleted. Reload the batch before continuing.',
        'Ya existe un aviso activo con el mismo número para este cliente.' => 'An active notice with the same number already exists for this client.',
        'Todas las filas del lote fueron procesadas.' => 'All rows in the batch were processed.',
        'Filas del lote procesadas' => 'Processed batch rows',
        'Sin filas con error' => 'No rows with errors',
        'Expediente creado' => 'Case file created',
        'El expediente existe.' => 'The case file exists.',
        'No existe el expediente importado.' => 'The imported case file does not exist.',
        'Número de aviso' => 'Notice number',
        'El expediente no tiene número de aviso.' => 'The case file does not have a notice number.',
        'Código MADE' => 'MADE code',
        'El expediente no tiene código MADE.' => 'The case file does not have a MADE code.',
        'Origen histórico del expediente' => 'Historical case file origin',
        'Vínculo con fila de importación' => 'Import row link',
        'PDF histórico archivado' => 'Historical PDF archived',
        'No existe la versión histórica esperada.' => 'The expected historical version does not exist.',
        'Origen de la versión' => 'Version origin',
        'Versión ligada al staging' => 'Version linked to staging',
        'Archivo PDF físico' => 'Physical PDF file',
        'No se encontró el PDF archivado en storage.' => 'The archived PDF was not found in storage.',
        'Tamaño del PDF' => 'PDF size',
        'SHA-256 del PDF' => 'PDF SHA-256',
        'No fue posible calcular SHA-256.' => 'SHA-256 could not be calculated.',
        'El SHA-256 canónico del snapshot no coincide.' => 'The canonical snapshot SHA-256 does not match.',
        'Cantidad total de piezas' => 'Total piece quantity',
        'Aviso único por cliente' => 'Unique notice per client',
        'Coherencia con staging' => 'Staging consistency',
        'Una de las fotografías está asociada a una mercancía que no pertenece a este expediente.' => 'One of the photos is associated with merchandise that does not belong to this case file.',
        'El perfil requiere un nombre y el nombre del Rig.' => 'The profile requires a name and the rig name.',
        'Sólo un administrador puede crear perfiles globales.' => 'Only an administrator can create global profiles.',
        'El desembarque no tiene un cliente asociado para guardar un perfil por cliente.' => 'The unloading record has no associated client for saving a client profile.',
        'El Excel debe contener al menos una mercancía y no más de 500 renglones.' => 'The Excel file must contain at least one merchandise line and no more than 500 lines.',
        'No se encontraron mercancías válidas para guardar.' => 'No valid merchandise lines were found to save.',
    ];

    return $translations;
}

function translateOperationalText(string $value, ?string $language = null): string
{
    $language = $language !== null ? normalizeLanguage($language) : getAppLanguage();
    if ($language !== 'en' || $value === '') {
        return $value;
    }

    $translations = operationalEnglishTranslations();
    if (array_key_exists($value, $translations)) {
        return $translations[$value];
    }

    $patterns = [
        '/^1 fotografías se añadieron correctamente al expediente\.$/u' => '1 photo was added to the case file.',
        '/^(\d+) fotografías se añadieron correctamente al expediente\.$/u' => '$1 photos were added to the case file.',
        '/^Puedes analizar hasta (\d+) archivos por lote\.$/u' => 'You can analyze up to $1 files per batch.',
        '/^(.+) no parece ser un PDF válido\.$/u' => '$1 does not appear to be a valid PDF.',
        '/^(.+) no parece ser un DOCX válido\.$/u' => '$1 does not appear to be a valid DOCX file.',
        '/^(.+) no parece ser un Word \.doc válido\.$/u' => '$1 does not appear to be a valid Word .doc file.',
        '/^El aviso #(\d+) ya fue procesado anteriormente\.$/u' => 'Notice #$1 was already processed.',
        '/^El aviso #(\d+) todavía no está marcado como listo después de la revisión\.$/u' => 'Notice #$1 is not yet marked as ready after review.',
        '/^El aviso #(\d+) no tiene PDF final disponible\.$/u' => 'Notice #$1 does not have a final PDF available.',
        '/^Pedimento #(\d+) inválido\.$/u' => 'Customs entry #$1 is invalid.',
        '/^Falta la clave del pedimento #(\d+)\.$/u' => 'The code is missing for customs entry #$1.',
        '/^Falta el número del pedimento #(\d+)\.$/u' => 'The number is missing for customs entry #$1.',
        '/^Falta el importador del pedimento #(\d+)\.$/u' => 'The importer is missing for customs entry #$1.',
        '/^Mercancía #(\d+) inválida\.$/u' => 'Merchandise line #$1 is invalid.',
        '/^Falta la descripción de la mercancía #(\d+)\.$/u' => 'The description is missing for merchandise line #$1.',
        '/^La cantidad de la mercancía #(\d+) debe ser mayor a cero\.$/u' => 'The quantity for merchandise line #$1 must be greater than zero.',
        '/^Un pedimento relacionado con la mercancía #(\d+) no coincide con los pedimentos revisados\.$/u' => 'A customs entry linked to merchandise line #$1 does not match the reviewed customs entries.',
        '/^1 fila\(s\) siguen pendientes de revisión\/importación\.$/u' => '1 row is still pending review/import.',
        '/^(\d+) fila\(s\) siguen pendientes de revisión\/importación\.$/u' => '$1 rows are still pending review/import.',
        '/^Coincidencias cliente\+número: (\d+)$/u' => 'Client + notice number matches: $1',
        '/^Fila de importación #(\d+)$/u' => 'Import row #$1',
        '/^No existe la fila de importación #(\d+)\.$/u' => 'Import row #$1 does not exist.',
        '/^La evidencia del acuse no pudo procesarse: (.+)$/u' => 'The acknowledgment evidence could not be processed: $1',
    ];
    foreach ($patterns as $pattern => $replacement) {
        if (preg_match($pattern, $value) === 1) {
            return (string) preg_replace($pattern, $replacement, $value);
        }
    }

    return $value;
}

/**
 * Localizes response messages without modifying business data contained in the
 * same JSON payload.
 */
function translateOperationalPayload(mixed $value, ?string $language = null, ?string $key = null): mixed
{
    $language = $language !== null ? normalizeLanguage($language) : getAppLanguage();
    if ($language !== 'en') {
        return $value;
    }

    if (is_array($value)) {
        $result = [];
        foreach ($value as $childKey => $childValue) {
            $result[$childKey] = translateOperationalPayload(
                $childValue,
                $language,
                is_string($childKey) ? $childKey : $key
            );
        }
        return $result;
    }

    if (! is_string($value)) {
        return $value;
    }

    $translatableKeys = ['message', 'detail', 'error', 'errors', 'label', 'duplicate_reason', 'import_error'];
    return in_array((string) $key, $translatableKeys, true)
        ? translateOperationalText($value, $language)
        : $value;
}
