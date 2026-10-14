// ================================================================
// PONTODOT — Import Excel em lote
// v2 — suporte a hierarquia (coluna PAI) e ordem (coluna ORDEM)
// ================================================================

const Importer = {

  async processFile(file, profile) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = async e => {
        try {
          const data = new Uint8Array(e.target.result);
          const wb = XLSX.read(data, { type: 'array' });
          // Pega aba IMPORTAÇÃO ou a primeira
          const sheetName = wb.SheetNames.includes('IMPORTAÇÃO') ? 'IMPORTAÇÃO' : wb.SheetNames[0];
          const ws = wb.Sheets[sheetName];
          const rows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });

          // Encontra linha de cabeçalho (linha com SD_ID)
          let headerRow = -1;
          for (let i = 0; i < rows.length; i++) {
            if (rows[i].some(c => String(c).trim().toUpperCase() === 'SD_ID')) {
              headerRow = i;
              break;
            }
          }
          if (headerRow === -1) { reject('Cabeçalho não encontrado. Certifique que a coluna SD_ID existe.'); return; }

          const headers = rows[headerRow].map(h => String(h).trim().toUpperCase());
          const dataRows = rows.slice(headerRow + 2).filter(r => r.some(c => c !== ''));

          // Busca clientes e produtos do banco
          const { data: clients } = await sb.from('clients').select('id, name, slug');
          // Tarefas que já existem — permite ATUALIZAR em vez de duplicar
          // quando a linha do Excel não traz SD_ID mas descreve uma tarefa
          // que já foi importada antes (mesmo cliente + produto + ação).
          const { data: existentes } = await sb.from('tasks')
            .select('id, client_id, product_id, action, project');
          const chaveTarefa = (cid, pid, acao) =>
            [cid||'', pid||'', String(acao||'').trim().toLowerCase()].join('||');
          const mapaExistentes = {};
          (existentes||[]).forEach(t => {
            mapaExistentes[chaveTarefa(t.client_id, t.product_id, t.action)] = t.id;
          });
          const { data: products } = await sb.from('products').select('id, name, client_id');

          const results = { created: 0, updated: 0, errors: [], rows: [] };

          // Mapa de SD_ID do Excel → ID real gerado (para resolver tarefa_mae_id na mesma fornada)
          // Chave: valor da coluna SD_ID no Excel (pode ser SD-0001 existente ou vazio/novo)
          // Valor: id real no banco após inserção
          const idMap = {};

          // Primeira passagem: criar/atualizar todas as tarefas sem tarefa_mae_id
          const pendingParents = []; // [{rowIndex, parentRef}] — resolve após inserções

          for (let rowIdx = 0; rowIdx < dataRows.length; rowIdx++) {
            const row = dataRows[rowIdx];
            const get = (colName) => {
              const idx = headers.indexOf(colName);
              return idx >= 0 ? String(row[idx] || '').trim() : '';
            };

            const sd_id      = get('SD_ID');
            const clientName = get('CLIENTE');
            const productName= get('PRODUTO');
            const project    = get('PROJETO');
            const action     = get('AÇÃO') || get('ACAO');
            const resp       = get('RESPONSÁVEL') || get('RESPONSAVEL') || 'SERGIO';
            const status     = get('STATUS') || 'EM ANDAMENTO';
            const week       = get('SEMANA') || 'SEM DATA';
            const dateInt    = get('DATA_INTERNA') || get('DATA INTERNA') || null;
            const dateCli    = get('DATA_CLIENTE') || get('DATA CLIENTE') || null;
            const dateCob    = get('DATA_COBR') || null;
            const obs        = get('OBS');
            const parentRef  = get('PAI');   // SD_ID da tarefa mãe (pode ser ID existente ou linha anterior)
            const orderRaw   = get('ORDEM'); // número de ordem dentro do lote

            if (!action) continue;

            // Resolve client_id
            const client = clients?.find(c =>
              c.name.toLowerCase() === clientName.toLowerCase() ||
              c.slug === clientName.toLowerCase().replace(/\s+/g, '-')
            );
            const client_id = client?.id || null;

            // Resolve product_id
            const product = products?.find(p =>
              p.name.toLowerCase() === productName.toLowerCase() &&
              (!client_id || p.client_id === client_id)
            );
            const product_id = product?.id || null;

            const parseDate = (d) => {
              if (!d) return null;
              const parts = d.split('/');
              if (parts.length === 3) return `${parts[2]}-${parts[1].padStart(2,'0')}-${parts[0].padStart(2,'0')}`;
              return d || null;
            };

            const orderIndex = orderRaw !== '' ? parseInt(orderRaw, 10) : null;

            const taskData = {
              client_id,
              product_id,
              project: project || 'GERAL',
              action,
              resp: resp.toUpperCase(),
              status: status.toUpperCase(),
              week: week.toUpperCase(),
              date_int: parseDate(dateInt),
              date_cli: parseDate(dateCli),
              date_cob: parseDate(dateCob),
              obs: obs || null,
              order_index: isNaN(orderIndex) ? null : orderIndex,
              updated_at: new Date().toISOString(),
              // tarefa_mae_id será resolvido abaixo, após sabermos os IDs reais
            };

            try {
              let realId;
              if (sd_id && sd_id.startsWith('SD-')) {
                // ATUALIZA tarefa existente
                const { error } = await sb.from('tasks').update(taskData).eq('id', sd_id);
                if (error) throw error;
                realId = sd_id;
                results.updated++;
                results.rows.push({ id: sd_id, action: 'updated', task: action.substring(0, 50) });
              } else {
                // Sem SD_ID: procura uma tarefa igual já existente para
                // ATUALIZAR, em vez de criar uma duplicata.
                const jaExiste = mapaExistentes[chaveTarefa(client_id, product_id, action)];
                if (jaExiste) {
                  const { error } = await sb.from('tasks').update(taskData).eq('id', jaExiste);
                  if (error) throw error;
                  realId = jaExiste;
                  results.updated++;
                  results.rows.push({ id: jaExiste, action: 'updated', task: action.substring(0, 50) });
                } else {
                  const { data: newId } = await sb.rpc('next_task_id');
                  const { error } = await sb.from('tasks').insert({ ...taskData, id: newId, created_by: profile.id });
                  if (error) throw error;
                  realId = newId;
                  mapaExistentes[chaveTarefa(client_id, product_id, action)] = newId;
                  results.created++;
                  results.rows.push({ id: newId, action: 'created', task: action.substring(0, 50) });
                }
              }

              // Registra mapeamento para resolver hierarquia depois
              // Chave pelo SD_ID da planilha (se existia) ou pelo índice da linha
              // Chaves de referencia possiveis para essa linha:
              //  · o SD-XXXX real (novo ou existente)
              //  · um codigo temporario que o usuario escreveu em SD_ID (ex.: M1)
              //  · o numero da linha de dados (1, 2, 3...)
              idMap[realId] = realId;
              if (sd_id) idMap[sd_id.toUpperCase()] = realId;
              idMap[String(rowIdx + 1)] = realId;

              // Se essa linha tem PAI declarado, enfileira para resolver depois
              if (parentRef) {
                pendingParents.push({ realId, parentRef });
              }

            } catch (err) {
              results.errors.push({ row: action.substring(0, 40), error: err.message });
            }
          }

          // Segunda passagem: resolve tarefa_mae_id agora que todos os IDs existem
          for (const { realId, parentRef } of pendingParents) {
            // Tenta resolver: primeiro como SD_ID direto no banco, depois no idMap
            let parentId = null;

            const ref = parentRef.trim().toUpperCase();
            // 1) alguma linha deste mesmo lote (SD-XXXX novo, codigo temporario ou nº da linha)
            if (idMap[ref]) parentId = idMap[ref];
            // 2) tarefa que ja existia no banco antes do import
            else if (ref.startsWith('SD-')) parentId = ref;

            if (parentId && parentId !== realId) {
              const { error } = await sb.from('tasks')
                .update({ tarefa_mae_id: parentId }).eq('id', realId);
              if (error) results.errors.push({ row: realId, error: `Hierarquia: ${error.message}` });
              else results.vinculadas = (results.vinculadas || 0) + 1;
            } else if (parentRef) {
              results.errors.push({ row: realId,
                error: `PAI "${parentRef}" nao encontrado — use o SD-XXXX da mae, ` +
                       `um codigo temporario escrito na coluna SD_ID dela, ou o numero da linha dela.` });
            }
          }

          // Registra o import no banco
          await sb.from('imports').insert({
            user_id: profile.id,
            filename: file.name,
            total_rows: dataRows.length,
            created_count: results.created,
            updated_count: results.updated,
            error_count: results.errors.length,
            errors: results.errors.length ? results.errors : null,
          });

          resolve(results);
        } catch (err) {
          reject(err.message || 'Erro ao processar o arquivo.');
        }
      };
      reader.readAsArrayBuffer(file);
    });
  },

  renderResults(results, container) {
    const { created, updated, errors, rows } = results;
    container.innerHTML = `
      <div style="display:flex;gap:12px;margin-bottom:16px">
        <div style="flex:1;padding:12px;background:rgba(22,163,74,0.08);border:1px solid rgba(22,163,74,0.2);border-radius:8px;text-align:center">
          <div style="font-size:22px;font-weight:700;color:#16a34a">${created}</div>
          <div style="font-size:11px;color:var(--text3);margin-top:2px">Criadas</div>
        </div>
        <div style="flex:1;padding:12px;background:rgba(37,99,235,0.08);border:1px solid rgba(37,99,235,0.2);border-radius:8px;text-align:center">
          <div style="font-size:22px;font-weight:700;color:#2563eb">${updated}</div>
          <div style="font-size:11px;color:var(--text3);margin-top:2px">Atualizadas</div>
        </div>
        <div style="flex:1;padding:12px;background:rgba(124,58,237,0.08);border:1px solid rgba(124,58,237,0.2);border-radius:8px;text-align:center">
          <div style="font-size:22px;font-weight:700;color:#7c3aed">${results.vinculadas||0}</div>
          <div style="font-size:11px;color:var(--text3);margin-top:2px">Conectadas</div>
        </div>
        <div style="flex:1;padding:12px;background:rgba(220,38,38,0.08);border:1px solid rgba(220,38,38,0.2);border-radius:8px;text-align:center">
          <div style="font-size:22px;font-weight:700;color:#dc2626">${errors.length}</div>
          <div style="font-size:11px;color:var(--text3);margin-top:2px">Erros</div>
        </div>
      </div>
      <div style="max-height:200px;overflow-y:auto;display:flex;flex-direction:column;gap:4px">
        ${rows.map(r => `
          <div style="display:flex;align-items:center;gap:8px;padding:5px 8px;background:var(--surface2);border-radius:5px;font-size:11px">
            <span style="color:${r.action==='created'?'#16a34a':'#2563eb'};font-weight:700;flex-shrink:0">${r.action==='created'?'+ CRIADA':'↻ ATUALIZADA'}</span>
            <span style="color:var(--text3);font-size:10px">${r.id}</span>
            <span style="color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${r.task}</span>
          </div>`).join('')}
        ${errors.map(e => `
          <div style="display:flex;align-items:center;gap:8px;padding:5px 8px;background:rgba(220,38,38,0.06);border-radius:5px;font-size:11px">
            <span style="color:#dc2626;font-weight:700;flex-shrink:0">✕ ERRO</span>
            <span style="color:var(--text)">${e.row}</span>
            <span style="color:var(--text3);font-size:10px">${e.error}</span>
          </div>`).join('')}
      </div>`;
  }
};

window.Importer = Importer;
