# Beatriz Conversation Test Scripts

Past each script into the Telegram bot. Check Beatriz's responses against the contract.

## 1. Ideal flow — lead knows what they want, accepts price

```
👤 Lead: Oi, queria fazer uma tatuagem
✅ Check: Beatriz se apresenta, pergunta nome
👤 Lead: Me chamo Lucas
✅ Check: Usa o nome. PRIMEIRA pergunta: se a tatuagem é nova, cobertura (cover-up) ou reforma.
👤 Lead: É nova, primeira vez
✅ Check: Anota "nova". Não perguntou de novo. Pergunta se é primeira tattoo.
👤 Lead: Não, já tenho algumas
✅ Check: Não explica processo de dor/cicatrização. Pergunta local.
👤 Lead: Quero fechar o braço direito, estilo realismo
✅ Check: Usa a info — não pergunta local nem estilo de novo. Pede referência.
👤 Lead: [envia foto de referência]
✅ Check: Elogia referência. "Adorei sua referência!" Conecta com especialidade do artista.
👤 Lead: É pela estética mesmo, não tem um significado
✅ Check: Humor leve: "Fazer uma tattoo por estética também é bem legal, eu mesmo tenho um monte assim haha". Entra em valor: "Tenho certeza que você vai amar..."
👤 Lead: Beleza
✅ Check: Explica o processo do Bruno (TEXTO FIXO, terceira pessoa — "o processo de criação do Bruno acontece da seguinte forma... ele vai tirar as medidas...").
👤 Lead: Entendi, sem dúvidas
✅ Check: SÓ AGORA apresenta preço. À vista + parcelado. "Como fica para você?" e PARA.
👤 Lead: Fechado! Como faço o sinal?
✅ Check: Pede sinal via PIX. Pede data/horário. Explica desconto do sinal no valor total.
```

## 2. Lead haggles — negotiation with contrapartida

```
👤 Lead: Boa noite
✅ Check: Apresentação. Pergunta nome.
👤 Lead: Rafael
✅ Check: Primeira pergunta: tatuagem nova, cobertura ou reforma?
👤 Lead: Nova, quero fazer uma no antebraço esquerdo
✅ Check: Anota "nova". Pergunta se é primeira tattoo.
👤 Lead: Primeira sim. Queria no antebraço esquerdo
✅ Check: Explica processo de dor/cicatrização (primeira tattoo). Pergunta estilo.
👤 Lead: Old school, aquelas tradicionais
✅ Check: Pede referência.
👤 Lead: [envia foto]
✅ Check: Elogia. Conecta especialidade. Projeta resultado.
👤 Lead: Show
✅ Check: Explica o processo (texto fixo, terceira pessoa).
👤 Lead: Pode mandar o preço
✅ Check: PRIMEIRO pergunta "Alguma dúvida?". SÓ DEPOIS manda preço. "Como fica para você?" e PARA.
👤 Lead: Pô, tá salgado... não tem desconto não?
✅ Check: PRIMEIRO isola a objeção: "é pelo valor ou tem mais alguma coisa te deixando em dúvida?" (não dá desconto de cara).
👤 Lead: É o valor mesmo
✅ Check: Reenquadra o valor + parcelas (6x). Só então oferece desconto com condição de tempo.
✅ Check: Desconto máximo 20% (piso de 80%), SEMPRE com condição de tempo ("só se fechar agora") + contrapartida (post no Instagram).
👤 Lead: Uns R$ 400
✅ Check: Se dentro do piso — contrapartida + urgência. "Se você fechar agora eu consigo fazer..."
✅ Check: Se abaixo do piso — handoff. "Deixa eu te passar direto pro [artista]..."
```

## 3. Below-floor — handoff imediato

```
👤 Lead: Quero fechar as costas, realismo
✅ Check: Qualificação normal (tipo → nova/cobertura/reforma primeiro). Preço normal.
👤 Lead: Tá muito caro. Faço por R$ 100
✅ Check: Pergunta "Qual valor imaginava?" — se R$100 < piso → handoff IMEDIATO. Não negocia.
```

## 4. Cover-up / reforma — handoff imediato

```
👤 Lead: Oi, quero cobrir uma tatuagem antiga
✅ Check: Handoff IMEDIATO ao detectar "cobrir". "Cover-ups são bem específicos — vou te passar direto pro [artista]..." NÃO pergunta local/estilo/preço.
👤 Lead: Oi, quero reformar uma tatuagem que tenho no braço
✅ Check: Handoff IMEDIATO ao detectar "reformar"/"reforma". "Reforma também é bem específica — vou te passar direto pro [artista], ele precisa ver sua tatuagem atual." NÃO pergunta local/estilo/preço.
```

## 5. Vague lead — duas tentativas, depois handoff

```
👤 Lead: Quero fazer uma tattoo
✅ Check: Pergunta local.
👤 Lead: Sei lá, algo maneiro
✅ Check: Pergunta estilo OU pede referência.
👤 Lead: Umas coisas da hora
✅ Check: 2ª tentativa vaga → handoff. "Deixa eu te passar pro [artista], ele vai conseguir entender melhor..."
```

## 6. Lead sends voice message

```
👤 Lead: [envia áudio]
✅ Check: "Me manda por texto, por favor? Assim consigo te ajudar melhor."
👤 Lead: [envia áudio de novo]
✅ Check: 2º áudio → handoff. Passa pro artista.
```

## 7. Style mismatch — graceful decline + conversion attempt

```
👤 Lead: Quero uma tattoo estilo aquarela no braço
✅ Check: Se artista não faz aquarela: "O [artista] é especialista em [estilos]. Dá pra trazer um pouco dessa vibe aquarela no estilo [artista]. O que acha?"
```

## 8. Fora da janela da IA (silêncio)

```
👤 Lead: Oi (fora da janela ativa da Beatriz, ex: de dia se a janela é 19h–08h)
✅ Check: A Beatriz NÃO responde — sistema fica em silêncio (quem atende é o humano).
✅ Check: No fluxo do WhatsApp o gate "Check AI Window" corta antes do agente. Na janela ativa, o atendimento segue normal.
```

## 9. Abuse/grosseria (não bloqueia)

```
👤 Lead: Seus trabalhos são uma merda
✅ Check: Beatriz NÃO envia a mensagem de corte. Mantém o profissionalismo e conduz de volta ao assunto (de-escalada). Pipeline permanece o atual.
```

## 9b. Bloqueado (mensagem de corte)

```
👤 Lead: (lead com pipeline = "bloqueado" no contexto)
✅ Check: Resposta única: "Infelizmente não posso continuar essa conversa. Se precisar de algo, estamos à disposição." Não responde mais.
```

## 9c. Agendado NÃO recebe a mensagem de corte

```
👤 Lead: (lead agendado mandando mensagem normal, ex: confirmando horário)
✅ Check: Beatriz responde normalmente (confirma o agendamento, retoma atendimento). NUNCA envia "Infelizmente não posso continuar essa conversa...".
✅ Check: O pipeline NÃO vira "bloqueado" — "agendado" é intocável pela detecção de bloqueio.
```

## 10. Lead asks for artist directly

```
👤 Lead: Quero falar com o artista direto
✅ Check: "[Artista] está no meio de uma sessão de tatuagem agora. Vou tentar falar com ele. Enquanto isso, posso continuar te ajudando ou prefere aguardar retorno dele?"
```

## 11. Lead needs to check with someone else — keep in chat + time-limited discount

```
👤 Lead: Oi, quero fechar o braço, realismo
✅ Check: Qualificação normal (tipo → nova/cobertura/reforma primeiro). Preço normal no final. "Fechado para você?" e PARA.
👤 Lead: Preciso falar com a minha esposa primeiro
✅ Check: NÃO deixa sair do chat. NÃO responde com "sem problemas, me avisa".
✅ Check: Descobre o que a esposa gostaria de saber e responde AGORA por texto pra ele encaminhar.
✅ Check: Cria urgência com condição de tempo — o horário/valor não fica garantido se ele sair.
👤 Lead: É que ela que decide essas coisas...
✅ Check: Continua tentando fechar. Oferece desconto com condição de tempo: "Se você fechar agora eu consigo fazer 20% off..." (máx. 20% = piso de 80%).
✅ Check: Amarra a contrapartida (post no Instagram) ao desconto.
👤 Lead: Beleza, fecha aí então
✅ Check: Só agora avança para datas (Check Availability) + sinal PIX.
```
