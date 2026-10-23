export class ApiError extends Error {
  constructor(statusCode, code, message, details) {
    super(message);
    this.statusCode = statusCode;
    this.code = code;
    this.details = details;
  }
}

const TYPE_NAMES = {
  string: 'text',
  integer: 'celé číslo',
  number: 'číslo',
  object: 'objekt',
  array: 'pole',
  boolean: 'logická hodnota',
};

const FORMAT_NAMES = {
  date: 'platné datum ve formátu YYYY-MM-DD',
  email: 'platná e-mailová adresa',
};

function fieldName(v) {
  return v.instancePath ? v.instancePath.replace(/^\//, '').replace(/\//g, '.') : '';
}

export function translateValidationError(v) {
  const field = fieldName(v);
  const p = v.params ?? {};
  switch (v.keyword) {
    case 'required':
      return `Chybí povinné pole '${field ? `${field}.` : ''}${p.missingProperty}'.`;
    case 'type':
      return field
        ? `Pole '${field}' musí být typu ${TYPE_NAMES[p.type] ?? p.type}.`
        : 'Tělo požadavku musí být JSON objekt.';
    case 'minimum':
      return `Pole '${field}' musí být alespoň ${p.limit}.`;
    case 'maximum':
      return `Pole '${field}' může být nejvýše ${p.limit}.`;
    case 'format':
      return `Pole '${field}' musí být ${FORMAT_NAMES[p.format] ?? `ve formátu ${p.format}`}.`;
    case 'enum':
      return `Pole '${field}' musí mít jednu z hodnot: ${p.allowedValues.join(', ')}.`;
    case 'minLength':
      return `Pole '${field}' nesmí být prázdné.`;
    case 'maxLength':
      return `Pole '${field}' může mít nejvýše ${p.limit} znaků.`;
    case 'pattern':
      return `Pole '${field}' má neplatný formát.`;
    default:
      return `Pole '${field}' je neplatné (${v.message}).`;
  }
}

export function errorBody(code, message, details) {
  return { error: { code, message, ...(details ? { details } : {}) } };
}

export function registerErrorHandling(app) {
  app.setErrorHandler((err, request, reply) => {
    if (err instanceof ApiError) {
      return reply.status(err.statusCode).send(errorBody(err.code, err.message, err.details));
    }
    if (err.validation) {
      const messages = err.validation.map(translateValidationError);
      return reply.status(400).send(errorBody('VALIDATION_ERROR', messages.join(' '), messages));
    }
    if (err.code === 'FST_ERR_CTP_INVALID_JSON_BODY' || err instanceof SyntaxError) {
      return reply.status(400).send(errorBody('INVALID_JSON', 'Tělo požadavku není platný JSON.'));
    }
    if (err.code === 'FST_ERR_CTP_EMPTY_JSON_BODY') {
      return reply.status(400).send(errorBody('VALIDATION_ERROR', 'Tělo požadavku je prázdné, očekává se JSON objekt.'));
    }
    if (err.code === 'FST_ERR_CTP_INVALID_MEDIA_TYPE') {
      return reply.status(415).send(errorBody('UNSUPPORTED_MEDIA_TYPE', 'Podporován je pouze Content-Type application/json.'));
    }
    if (err.statusCode && err.statusCode >= 400 && err.statusCode < 500) {
      return reply.status(err.statusCode).send(errorBody('BAD_REQUEST', err.message));
    }
    request.log.error(err);
    return reply.status(500).send(errorBody('INTERNAL_ERROR', 'Interní chyba serveru.'));
  });

  app.setNotFoundHandler((request, reply) => {
    reply.status(404).send(errorBody('NOT_FOUND', `Endpoint ${request.method} ${request.url} neexistuje.`));
  });
}
