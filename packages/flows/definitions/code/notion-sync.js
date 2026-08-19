// Notion Sync — payload builder for the Beatriz → Notion pipeline
// Runs in n8n Code node. Receives lead data + artist Notion config from Postgres.
//
// Builds: 1) search filter for clientes DB  2) page properties for create/update
//          3) project creation payload (if agendado)

const lead = $input.first().json;

const situacaoMap = {
  'novo':               'Contato Iniciado',
  'qualificando':       'Contato Iniciado',
  'orcamento_enviado':  'Resposta pendente',
  'aguardando_deposito': 'Esperando Sinal',
  'agendado':           'Tatuagem agendada',
  'fechado':            'Cliente Adquirido',
  'perdido':            'Cliente Perdido',
  'aguardando_artista': 'Resposta pendente',
};

const situacao = situacaoMap[lead.pipeline_status] || 'Contato Iniciado';
const telefone = (lead.telefone || '').replace(/\D/g, '');
const nome = lead.nome || 'Lead sem nome';

// ── Notion search filter (find existing lead by telefone) ──
const searchBody = {
  filter: {
    property: 'Telefone',
    phone_number: { equals: telefone }
  },
  page_size: 1,
};

// ── Clientes page properties (for create or update) ──
const pageProperties = {
  'Nome': {
    title: [{ text: { content: nome } }]
  },
  'Telefone': {
    phone_number: telefone
  },
  'Situação': {
    select: { name: situacao }
  },
};

// Email is optional — only include if mapped in future
// const email = lead.email;
// if (email) pageProperties['Email'] = { email: email };

// ── Booking data (for project creation) ──
let projectPayload = null;
if (lead.pipeline_status === 'agendado' || lead.booked_date) {
  const valorReais = lead.table_price
    ? Math.round(lead.table_price / 100)
    : null;

  const agendamento = lead.booked_date || null;

  const projectProperties = {
    'Cliente': {
      relation: [{ id: '__CLIENTE_PAGE_ID__' }]
    },
    'Status': {
      select: { name: 'Aguardando Sinal' }
    },
    'Agendamento': agendamento ? { date: { start: agendamento } } : undefined,
  };

  if (valorReais) {
    projectProperties['Valor'] = { number: valorReais };
  }

  if (lead.tatuador_nome) {
    projectProperties['Tatuador'] = { rich_text: [{ text: { content: lead.tatuador_nome } }] };
  }

  projectPayload = {
    parent: {
      type: 'data_source_id',
      data_source_id: lead.notion_projects_database_id,
    },
    properties: projectProperties,
  };
}

// ── Notion config ──
const notionToken = lead.notion_token;
const clientesDbId = lead.notion_clientes_database_id;

return [{
  json: {
    notion_token: notionToken,
    clientes_db_id: clientesDbId,
    project_payload: projectPayload,
    search_body: searchBody,
    page_properties: pageProperties,
    pipeline_status: lead.pipeline_status,
    telefone: telefone,
    nome: nome,
    lead_id: lead.lead_id,
    artist_id: lead.artist_id,
  }
}];
