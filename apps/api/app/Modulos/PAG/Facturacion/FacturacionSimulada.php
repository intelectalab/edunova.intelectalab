<?php

namespace App\Modulos\PAG\Facturacion;

/**
 * Proveedor simulado: arma el XML UBL 2.1 básico y responde «aceptado» sin salir del servidor.
 * Se usa en desarrollo, pruebas y en el servidor de pruebas hasta conectar el proveedor real
 * (FACTURACION_SIMULADA=false y el adaptador del proveedor en services.facturacion.adaptadores).
 */
class FacturacionSimulada implements ProveedorFacturacion
{
    public function emitir(array $documento, array $credenciales, string $ambiente): array
    {
        if (($documento['cliente']['nombre'] ?? '') === 'FALLA-PROVEEDOR') {
            throw new \RuntimeException('El proveedor simulado no respondió.');
        }
        $xml = self::xml($documento);

        return [
            'estado' => 'aceptado', 'xml' => $xml,
            'mensaje' => "La {$documento['nombre_tipo']} {$documento['serie']}-{$documento['numero']} ha sido aceptada (simulado).",
            'respuesta' => ['simulado' => true, 'ambiente' => $ambiente, 'hash' => base64_encode(hash('sha256', $xml, true)), 'codigo' => '0'],
        ];
    }

    public static function xml(array $d): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $raiz = $d['tipo'] === '07' ? 'CreditNote' : 'Invoice';
        $lineas = '';
        foreach ($d['items'] as $i => $it) {
            $lineas .= '<cac:'.($d['tipo'] === '07' ? 'CreditNoteLine' : 'InvoiceLine').'><cbc:ID>'.($i + 1).'</cbc:ID>'
                .'<cbc:LineExtensionAmount currencyID="PEN">'.number_format($it['total'], 2, '.', '').'</cbc:LineExtensionAmount>'
                .'<cac:Item><cbc:Description>'.$e($it['descripcion']).'</cbc:Description></cac:Item>'
                .'<cac:TaxTotal><cac:TaxSubtotal><cac:TaxCategory><cbc:TaxExemptionReasonCode>'.$e($it['afectacion_igv']).'</cbc:TaxExemptionReasonCode></cac:TaxCategory></cac:TaxSubtotal></cac:TaxTotal>'
                .'</cac:'.($d['tipo'] === '07' ? 'CreditNoteLine' : 'InvoiceLine').'>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            ."<$raiz xmlns=\"urn:oasis:names:specification:ubl:schema:xsd:$raiz-2\" xmlns:cac=\"urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2\" xmlns:cbc=\"urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2\">"
            .'<cbc:UBLVersionID>2.1</cbc:UBLVersionID><cbc:ID>'.$e($d['serie'].'-'.$d['numero']).'</cbc:ID><cbc:IssueDate>'.$e($d['fecha_emision']).'</cbc:IssueDate>'
            .($d['tipo'] !== '07' ? '<cbc:InvoiceTypeCode>'.$e($d['tipo']).'</cbc:InvoiceTypeCode>' : '<cac:DiscrepancyResponse><cbc:ReferenceID>'.$e($d['referencia'] ?? '').'</cbc:ReferenceID><cbc:Description>'.$e($d['motivo'] ?? '').'</cbc:Description></cac:DiscrepancyResponse>')
            .'<cbc:DocumentCurrencyCode>PEN</cbc:DocumentCurrencyCode>'
            .'<cac:AccountingSupplierParty><cac:Party><cac:PartyIdentification><cbc:ID schemeID="6">'.$e($d['emisor']['ruc']).'</cbc:ID></cac:PartyIdentification><cac:PartyLegalEntity><cbc:RegistrationName>'.$e($d['emisor']['razon_social']).'</cbc:RegistrationName></cac:PartyLegalEntity></cac:Party></cac:AccountingSupplierParty>'
            .'<cac:AccountingCustomerParty><cac:Party><cac:PartyIdentification><cbc:ID schemeID="'.$e($d['cliente']['tipo_doc']).'">'.$e($d['cliente']['numero_doc']).'</cbc:ID></cac:PartyIdentification><cac:PartyLegalEntity><cbc:RegistrationName>'.$e($d['cliente']['nombre']).'</cbc:RegistrationName></cac:PartyLegalEntity></cac:Party></cac:AccountingCustomerParty>'
            .'<cac:TaxTotal><cbc:TaxAmount currencyID="PEN">'.number_format($d['igv'], 2, '.', '').'</cbc:TaxAmount></cac:TaxTotal>'
            .'<cac:LegalMonetaryTotal><cbc:PayableAmount currencyID="PEN">'.number_format($d['total'], 2, '.', '').'</cbc:PayableAmount></cac:LegalMonetaryTotal>'
            .$lineas."</$raiz>";
    }
}
