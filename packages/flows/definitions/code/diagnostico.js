const lead = $input.first().json;

const nome = lead.nome || 'Cliente';
const studio = lead.studio || '';
const whatsapp = lead.whatsapp || '';
const email = lead.email || '';
const instagram = lead.instagram ? '@' + String(lead.instagram).replace(/^@/, '') : '';
const faturamento = Number(lead.faturamento) || 0;
const ticket = Number(lead.ticket) || 0;

const sessoesImplicitas = ticket > 0 ? Math.round(faturamento / ticket) : 0;
const BENCHMARK_TICKET = 3000;
const ticketAbaixoBenchmark = ticket > 0 && ticket < BENCHMARK_TICKET;
const gapTicket = ticketAbaixoBenchmark ? BENCHMARK_TICKET - ticket : 0;

const brl = (v) => 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const brlInt = (v) => 'R$ ' + Math.round(Number(v || 0)).toLocaleString('pt-BR');

const tgText = [
  '🚨 *NOVO DIAGNÓSTICO PARA APROVAÇÃO*',
  '',
  '*Cliente:* ' + nome,
  studio ? '*Estúdio:* ' + studio : '',
  '*E-mail:* ' + email,
  '*WhatsApp:* ' + whatsapp,
  instagram ? '*Instagram:* ' + instagram : '',
  '',
  '*Faturamento:* ' + brl(faturamento),
  '*Ticket médio:* ' + brl(ticket),
  '*Sessões/mês (implícitas):* ' + sessoesImplicitas,
  ticketAbaixoBenchmark ? '*⚠️ Ticket abaixo do benchmark R$ 3.000+*' : '*✅ Ticket dentro do padrão alto*',
  '',
  'Revisar e enviar diagnóstico por e-mail?'
].filter(Boolean).join('\n');

const gapLinha = ticketAbaixoBenchmark
  ? `Seu ticket médio de ${brl(ticket)} está <strong>${brl(gapTicket)} abaixo</strong> do padrão de alto valor (R$ 3.000+). Isso impacta diretamente sua receita por sessão e limita a margem de quem capta clientes premium.`
  : 'Seu ticket médio está dentro do padrão de alto valor (R$ 3.000+). O foco agora é volume qualificado e recorrência de agenda.';

const htmlEmail = `
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Diagnóstico Gratuito VAIF</title>
</head>
<body style="margin:0;padding:0;background-color:#0A0A0A;font-family:Montserrat,Arial,sans-serif;color:#F2EDE4;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0A0A0A;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#121212;border:1px solid #222222;border-radius:12px;overflow:hidden;">
          <tr>
            <td style="padding:32px 40px;text-align:center;background:#121212;border-bottom:1px solid #222222;">
              <div style="font-family:Georgia,'Times New Roman',serif;color:#D4B04C;font-size:14px;letter-spacing:3px;text-transform:uppercase;margin-bottom:8px;">VAIF · Diagnóstico Gratuito</div>
              <div style="font-family:Georgia,'Times New Roman',serif;color:#F2EDE4;font-size:28px;line-height:1.3;font-weight:600;">Olá, ${nome}!</div>
              <div style="color:#A09A8E;font-size:14px;margin-top:8px;">Seu diagnóstico personalizado chegou</div>
            </td>
          </tr>
          <tr>
            <td style="padding:32px 40px;">
              <div style="color:#D4B04C;font-family:Georgia,serif;font-size:18px;font-weight:600;margin-bottom:16px;">◆ Visão Geral</div>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                <tr>
                  <td style="width:33%;background:#0A0A0A;border:1px solid #222222;border-radius:8px;padding:16px;text-align:center;">
                    <div style="color:#A09A8E;font-size:11px;letter-spacing:1px;text-transform:uppercase;">Faturamento</div>
                    <div style="color:#F2EDE4;font-size:20px;font-weight:700;margin-top:6px;">${brlInt(faturamento)}</div>
                  </td>
                  <td style="width:4%;"></td>
                  <td style="width:33%;background:#0A0A0A;border:1px solid #222222;border-radius:8px;padding:16px;text-align:center;">
                    <div style="color:#A09A8E;font-size:11px;letter-spacing:1px;text-transform:uppercase;">Ticket Médio</div>
                    <div style="color:#F2EDE4;font-size:20px;font-weight:700;margin-top:6px;">${brlInt(ticket)}</div>
                  </td>
                  <td style="width:4%;"></td>
                  <td style="width:33%;background:#0A0A0A;border:1px solid #222222;border-radius:8px;padding:16px;text-align:center;">
                    <div style="color:#A09A8E;font-size:11px;letter-spacing:1px;text-transform:uppercase;">Sessões/mês</div>
                    <div style="color:#F2EDE4;font-size:20px;font-weight:700;margin-top:6px;">${sessoesImplicitas}</div>
                  </td>
                </tr>
              </table>

              <div style="color:#D4B04C;font-family:Georgia,serif;font-size:18px;font-weight:600;margin-bottom:12px;">◆ Diagnóstico Financeiro</div>
              <p style="color:#E8E2D6;font-size:14px;line-height:1.7;margin:0 0 28px 0;">${gapLinha}</p>

              <div style="color:#D4B04C;font-family:Georgia,serif;font-size:18px;font-weight:600;margin-bottom:12px;">◆ Presença Digital</div>
              <p style="color:#E8E2D6;font-size:14px;line-height:1.7;margin:0 0 28px 0;">${instagram ? `Seu perfil <strong>${instagram}</strong> será analisado pelo nosso time: bio, posicionamento e conversão em orçamentos.` : 'Nosso time vai analisar sua presença digital e posicionamento.'}</p>

              <div style="color:#D4B04C;font-family:Georgia,serif;font-size:18px;font-weight:600;margin-bottom:12px;">◆ Top 3 Oportunidades</div>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                <tr>
                  <td style="background:#0A0A0A;border:1px solid #222222;border-radius:8px;padding:16px;margin-bottom:8px;display:block;">
                    <div style="color:#D4B04C;font-weight:700;font-size:14px;">1 · Elevar o ticket médio</div>
                    <div style="color:#A09A8E;font-size:13px;line-height:1.6;margin-top:4px;">${ticketAbaixoBenchmark ? 'Estruturar preço por projeto e valor entregue para alcançar o padrão premium.' : 'Manter o posicionamento premium e qualificar para volume de alto valor.'}</div>
                  </td>
                </tr>
                <tr>
                  <td style="background:#0A0A0A;border:1px solid #222222;border-radius:8px;padding:16px;display:block;">
                    <div style="color:#D4B04C;font-weight:700;font-size:14px;">2 · Captação qualificada</div>
                    <div style="color:#A09A8E;font-size:13px;line-height:1.6;margin-top:4px;">Funil de anúncios segmentado por bairro, renda e estilo artístico.</div>
                  </td>
                </tr>
                <tr>
                  <td style="background:#0A0A0A;border:1px solid #222222;border-radius:8px;padding:16px;display:block;">
                    <div style="color:#D4B04C;font-weight:700;font-size:14px;">3 · Atendimento que não dorme</div>
                    <div style="color:#A09A8E;font-size:13px;line-height:1.6;margin-top:4px;">Atendente virtual que responde, qualifica e agenda 24h — sem perder lead por demora.</div>
                  </td>
                </tr>
              </table>

              <div style="color:#D4B04C;font-family:Georgia,serif;font-size:18px;font-weight:600;margin-bottom:12px;">◆ Plano de Ação</div>
              <p style="color:#E8E2D6;font-size:14px;line-height:1.7;margin:0 0 28px 0;">Nosso especialista preparou um plano de ação personalizado para o seu estúdio. A próxima etapa é uma <strong>call estratégica gratuita</strong> para detalharmos os próximos passos.</p>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center">
                    <a href="https://wa.me/5521999553136" style="background-color:#D4B04C;color:#0A0A0A;text-decoration:none;font-weight:700;font-size:14px;letter-spacing:1px;text-transform:uppercase;padding:16px 32px;border-radius:4px;display:inline-block;">Agendar Call Estratégica →</a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:24px 40px;text-align:center;background:#0A0A0A;border-top:1px solid #222222;">
              <div style="color:#A09A8E;font-size:12px;line-height:1.6;">VAIF · Agência de Escala para Estúdios de Tatuagem</div>
              <div style="color:#555;font-size:11px;margin-top:4px;">contato@vaif.com.br · @vaifmarketing</div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
`;

return [{
  json: {
    ...lead,
    nome,
    studio,
    whatsapp,
    email,
    instagram,
    faturamento,
    ticket,
    sessoes_implicitas: sessoesImplicitas,
    ticket_abaixo_benchmark: ticketAbaixoBenchmark,
    tg_text: tgText,
    html_email: htmlEmail
  }
}];