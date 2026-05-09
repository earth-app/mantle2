<?php

namespace Drupal\mantle2\EventSubscriber;

use DOMDocument;
use DOMElement;
use SimpleXMLElement;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class XmlContentNegotiationSubscriber implements EventSubscriberInterface
{
	private const REQUEST_ROOT = 'request';
	private const RESPONSE_ROOT = 'response';

	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::REQUEST => ['onRequest', 20],
			KernelEvents::RESPONSE => ['onResponse', -20],
		];
	}

	public function onRequest(RequestEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}

		$request = $event->getRequest();
		if (!$this->shouldHandleXmlRequest($request)) {
			return;
		}

		$content = trim((string) $request->getContent());
		if ($content === '') {
			return;
		}

		$data = self::xmlToArray($content);
		if ($data === []) {
			$event->setResponse(
				new JsonResponse(
					['message' => 'Invalid XML body', 'code' => 400],
					Response::HTTP_BAD_REQUEST,
				),
			);
			return;
		}

		$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			return;
		}

		self::setRequestContent($request, $json);
		$request->request->replace($data);
		$request->headers->set('Content-Type', 'application/json; charset=UTF-8');
	}

	public function onResponse(ResponseEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}

		$request = $event->getRequest();
		if (!$this->shouldHandleXmlResponse($request)) {
			return;
		}

		$response = $event->getResponse();
		if (!$response instanceof JsonResponse) {
			return;
		}

		$content = (string) $response->getContent();
		if ($content === '') {
			return;
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return;
		}

		$xml = self::arrayToXml($data, self::RESPONSE_ROOT);
		$xmlResponse = new Response($xml, $response->getStatusCode(), $response->headers->all());
		$xmlResponse->headers->set('Content-Type', 'application/xml; charset=UTF-8');
		$event->setResponse($xmlResponse);
	}

	public static function xmlToArray(string $xml): array
	{
		$element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA);
		if ($element === false) {
			return [];
		}

		$value = self::xmlNodeToValue($element);
		if (is_array($value)) {
			return $value;
		}

		return ['value' => $value];
	}

	public static function arrayToXml(
		mixed $data,
		string $rootElement = self::RESPONSE_ROOT,
	): string {
		$document = new DOMDocument('1.0', 'UTF-8');
		$document->formatOutput = true;

		$root = $document->createElement(self::sanitizeXmlTag($rootElement));
		$document->appendChild($root);
		self::appendValueToXml($document, $root, $data);

		return $document->saveXML($document->documentElement) ?: '';
	}

	private function shouldHandleXmlRequest(Request $request): bool
	{
		if (!str_starts_with($request->getPathInfo(), '/v2/')) {
			return false;
		}

		$contentType = strtolower((string) $request->headers->get('Content-Type', ''));
		return $contentType !== '' &&
			(str_contains($contentType, 'application/xml') ||
				str_contains($contentType, 'text/xml') ||
				str_contains($contentType, '+xml'));
	}

	private function shouldHandleXmlResponse(Request $request): bool
	{
		if (!str_starts_with($request->getPathInfo(), '/v2/')) {
			return false;
		}

		// first, check "format" query parameter
		$format = strtolower((string) $request->query->get('format', ''));
		if ($format === 'json') {
			return false;
		}
		if ($format === 'xml') {
			return true;
		}

		// then check "Accept" header for XML content types
		$accept = strtolower((string) $request->headers->get('Accept', ''));
		if (
			$accept !== '' &&
			(str_contains($accept, 'application/xml') ||
				str_contains($accept, 'text/xml') ||
				str_contains($accept, '+xml'))
		) {
			return true;
		}

		// finally, if request body itself is XML, default response to XML as well
		$contentType = strtolower((string) $request->headers->get('Content-Type', ''));
		return $contentType !== '' &&
			(str_contains($contentType, 'application/xml') ||
				str_contains($contentType, 'text/xml') ||
				str_contains($contentType, '+xml'));
	}

	private static function xmlNodeToValue(SimpleXMLElement $element): mixed
	{
		$children = $element->children();
		if ($children->count() === 0) {
			return self::castScalar((string) $element);
		}

		$grouped = [];
		foreach ($children as $name => $child) {
			$grouped[$name][] = self::xmlNodeToValue($child);
		}

		if (count($grouped) === 1) {
			$names = array_keys($grouped);
			$firstName = $names[0];
			$values = $grouped[$firstName];

			if ($firstName === 'item' || count($values) > 1) {
				return $values;
			}

			return [$firstName => $values[0]];
		}

		$result = [];
		foreach ($grouped as $name => $values) {
			$result[$name] = count($values) === 1 ? $values[0] : $values;
		}

		return $result;
	}

	private static function appendValueToXml(
		DOMDocument $document,
		DOMElement $parent,
		mixed $value,
	): void {
		if (is_array($value)) {
			if (array_is_list($value)) {
				foreach ($value as $item) {
					$child = $document->createElement('item');
					$parent->appendChild($child);
					self::appendValueToXml($document, $child, $item);
				}

				return;
			}

			foreach ($value as $key => $childValue) {
				$tagName = self::sanitizeXmlTag((string) $key);
				$child = $document->createElement($tagName);
				$parent->appendChild($child);
				self::appendValueToXml($document, $child, $childValue);
			}

			return;
		}

		if ($value === null) {
			return;
		}

		if (is_bool($value)) {
			$parent->appendChild($document->createTextNode($value ? 'true' : 'false'));
			return;
		}

		$parent->appendChild($document->createTextNode((string) $value));
	}

	private static function sanitizeXmlTag(string $tag): string
	{
		$tag = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', $tag) ?: 'item';
		if (!preg_match('/^[A-Za-z_]/', $tag)) {
			$tag = 'item_' . $tag;
		}

		return $tag;
	}

	private static function castScalar(string $value): mixed
	{
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		if (strcasecmp($value, 'true') === 0) {
			return true;
		}

		if (strcasecmp($value, 'false') === 0) {
			return false;
		}

		if (strcasecmp($value, 'null') === 0) {
			return null;
		}

		if (preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/', $value)) {
			return str_contains($value, '.') ? (float) $value : (int) $value;
		}

		return $value;
	}

	private static function setRequestContent(Request $request, string $content): void
	{
		$reflection = new \ReflectionObject($request);
		if (!$reflection->hasProperty('content')) {
			return;
		}

		$property = $reflection->getProperty('content');
		$property->setValue($request, $content);
	}
}
