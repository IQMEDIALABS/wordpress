<?php

/**
 *
 * ## XML Version support
 *
 * This class implements parts of the XML 1.0 specification:
 * https://www.w3.org/TR/xml/
 *
 * The final goal is to support both 1.0 and 1.1 depending on the
 * initial processing instruction (<?xml version="1.0" ?>). We're
 * starting with 1.0, however, because most that's what most WXR
 * files declare.
 *
 * @TODO: Support processing directives (<?xml version="1.0" ?>).
 * @TODO: Support (or ignore) <!DOCTYPE declarations
 *        https://www.w3.org/TR/xml/#sec-prolog-dtd
 * @TODO: Support (or ignore) <!ATTLIST declarations
 *        https://www.w3.org/TR/xml/#attdecls
 * @TODO: Support (or ignore) <!ENTITY declarations
 *        https://www.w3.org/TR/xml/#sec-entity-decl
 * @TODO: Support (or ignore) conditional sections
 *        https://www.w3.org/TR/xml/#sec-condition-sect
 */
class WP_XML_Tag_Processor {
	/**
	 * The maximum number of bookmarks allowed to exist at
	 * any given time.
	 *
	 * @since WP_VERSION
	 * @var int
	 *
	 * @see WP_XML_Tag_Processor::set_bookmark()
	 */
	const MAX_BOOKMARKS = 10;

	/**
	 * Maximum number of times seek() can be called.
	 * Prevents accidental infinite loops.
	 *
	 * @since WP_VERSION
	 * @var int
	 *
	 * @see WP_XML_Tag_Processor::seek()
	 */
	const MAX_SEEK_OPS = 1000;

	/**
	 * The XML document to parse.
	 *
	 * @since WP_VERSION
	 * @var string
	 */
	protected $xml;

	/**
	 * The last query passed to next_tag().
	 *
	 * @since WP_VERSION
	 * @var array|null
	 */
	private $last_query;

	/**
	 * The tag name this processor currently scans for.
	 *
	 * @since WP_VERSION
	 * @var string|null
	 */
	private $sought_tag_name;

	/**
	 * The match offset this processor currently scans for.
	 *
	 * @since WP_VERSION
	 * @var int|null
	 */
	private $sought_match_offset;

	/**
	 * Whether to visit tag closers, e.g. </div>, when walking an input document.
	 *
	 * @since WP_VERSION
	 * @var bool
	 */
	private $stop_on_tag_closers;

	/**
	 * Specifies mode of operation of the parser at any given time.
	 *
	 * | State           | Meaning                                                              |
	 * | ----------------|----------------------------------------------------------------------|
	 * | *Ready*         | The parser is ready to run.                                          |
	 * | *Complete*      | There is nothing left to parse.                                      |
	 * | *Incomplete*    | The XML ended in the middle of a token; nothing more can be parsed. |
	 * | *Matched tag*   | Found an XML tag; it's possible to modify its attributes.           |
	 * | *Text node*     | Found a #text node; this is plaintext and modifiable.                |
	 * | *CDATA node*    | Found a CDATA section; this is modifiable.                           |
	 * | *Comment*       | Found a comment or bogus comment; this is modifiable.                |
	 * | *Presumptuous*  | Found an empty tag closer: `</>`.                                    |
	 *
	 * @since WP_VERSION
	 *
	 * @see WP_XML_Tag_Processor::STATE_READY
	 * @see WP_XML_Tag_Processor::STATE_COMPLETE
	 * @see WP_XML_Tag_Processor::STATE_INCOMPLETE_INPUT
	 * @see WP_XML_Tag_Processor::STATE_MATCHED_TAG
	 * @see WP_XML_Tag_Processor::STATE_TEXT_NODE
	 * @see WP_XML_Tag_Processor::STATE_CDATA_NODE
	 * @see WP_XML_Tag_Processor::STATE_COMMENT
	 * @see WP_XML_Tag_Processor::STATE_DOCTYPE
	 * @see WP_XML_Tag_Processor::STATE_PRESUMPTUOUS_TAG
	 *
	 * @var string
	 */
	protected $parser_state = self::STATE_READY;

	/**
	 * What kind of syntax token became an XML comment.
	 *
	 * Since there are many ways in which XML syntax can create an XML comment,
	 * this indicates which of those caused it. This allows the Tag Processor to
	 * represent more from the original input document than would appear in the DOM.
	 *
	 * @since WP_VERSION
	 *
	 * @var string|null
	 */
	protected $comment_type = null;

	/**
	 * How many bytes from the original XML document have been read and parsed.
	 *
	 * This value points to the latest byte offset in the input document which
	 * has been already parsed. It is the internal cursor for the Tag Processor
	 * and updates while scanning through the XML tokens.
	 *
	 * @since WP_VERSION
	 * @var int
	 */
	private $bytes_already_parsed = 0;

	/**
	 * Byte offset in input document where current token starts.
	 *
	 * Example:
	 *
	 *     <div id="test">...
	 *     01234
	 *     - token starts at 0
	 *
	 * @since WP_VERSION
	 *
	 * @var int|null
	 */
	private $token_starts_at;

	/**
	 * Byte length of current token.
	 *
	 * Example:
	 *
	 *     <div id="test">...
	 *     012345678901234
	 *     - token length is 14 - 0 = 14
	 *
	 *     a <!-- comment --> is a token.
	 *     0123456789 123456789 123456789
	 *     - token length is 17 - 2 = 15
	 *
	 * @since WP_VERSION
	 *
	 * @var int|null
	 */
	private $token_length;

	/**
	 * Byte offset in input document where current tag name starts.
	 *
	 * Example:
	 *
	 *     <div id="test">...
	 *     01234
	 *      - tag name starts at 1
	 *
	 * @since WP_VERSION
	 *
	 * @var int|null
	 */
	private $tag_name_starts_at;

	/**
	 * Byte length of current tag name.
	 *
	 * Example:
	 *
	 *     <div id="test">...
	 *     01234
	 *      --- tag name length is 3
	 *
	 * @since WP_VERSION
	 *
	 * @var int|null
	 */
	private $tag_name_length;

	/**
	 * Byte offset into input document where current modifiable text starts.
	 *
	 * @since WP_VERSION
	 *
	 * @var int
	 */
	private $text_starts_at;

	/**
	 * Byte length of modifiable text.
	 *
	 * @since WP_VERSION
	 *
	 * @var string
	 */
	private $text_length;

	/**
	 * Whether the current tag is an opening tag, e.g. <div>, or a closing tag, e.g. </div>.
	 *
	 * @var bool
	 */
	private $is_closing_tag;

	/**
	 * Lazily-built index of attributes found within an XML tag, keyed by the attribute name.
	 *
	 * Example:
	 *
	 *     // Supposing the parser is working through this content
	 *     // and stops after recognizing the `id` attribute.
	 *     // <div id="test-4" class=outline title="data:text/plain;base64=asdk3nk1j3fo8">
	 *     //                 ^ parsing will continue from this point.
	 *     $this->attributes = array(
	 *         'id' => new WP_XML_Attribute_Token( 'id', 9, 6, 5, 11, false )
	 *     );
	 *
	 *     // When picking up parsing again, or when asking to find the
	 *     // `class` attribute we will continue and add to this array.
	 *     $this->attributes = array(
	 *         'id'    => new WP_XML_Attribute_Token( 'id', 9, 6, 5, 11, false ),
	 *         'class' => new WP_XML_Attribute_Token( 'class', 23, 7, 17, 13, false )
	 *     );
	 *
	 * @since WP_VERSION
	 * @var WP_HTML_Attribute_Token[]
	 */
	private $attributes = array();

	/**
	 * Tracks a semantic location in the original XML which
	 * shifts with updates as they are applied to the document.
	 *
	 * @since WP_VERSION
	 * @var WP_HTML_Span[]
	 */
	protected $bookmarks = array();

	/**
	 * Lexical replacements to apply to input XML document.
	 *
	 * "Lexical" in this class refers to the part of this class which
	 * operates on pure text _as text_ and not as XML. There's a line
	 * between the public interface, with XML-semantic methods like
	 * `set_attribute` and `add_class`, and an internal state that tracks
	 * text offsets in the input document.
	 *
	 * When higher-level XML methods are called, those have to transform their
	 * operations (such as setting an attribute's value) into text diffing
	 * operations (such as replacing the sub-string from indices A to B with
	 * some given new string). These text-diffing operations are the lexical
	 * updates.
	 *
	 * As new higher-level methods are added they need to collapse their
	 * operations into these lower-level lexical updates since that's the
	 * Tag Processor's internal language of change. Any code which creates
	 * these lexical updates must ensure that they do not cross XML syntax
	 * boundaries, however, so these should never be exposed outside of this
	 * class or any classes which intentionally expand its functionality.
	 *
	 * These are enqueued while editing the document instead of being immediately
	 * applied to avoid processing overhead, string allocations, and string
	 * copies when applying many updates to a single document.
	 *
	 * Example:
	 *
	 *     // Replace an attribute stored with a new value, indices
	 *     // sourced from the lazily-parsed XML recognizer.
	 *     $start  = $attributes['src']->start;
	 *     $length = $attributes['src']->length;
	 *     $modifications[] = new WP_HTML_Text_Replacement( $start, $length, $new_value );
	 *
	 *     // Correspondingly, something like this will appear in this array.
	 *     $lexical_updates = array(
	 *         WP_HTML_Text_Replacement( 14, 28, 'https://my-site.my-domain/wp-content/uploads/2014/08/kittens.jpg' )
	 *     );
	 *
	 * @since WP_VERSION
	 * @var WP_HTML_Text_Replacement[]
	 */
	protected $lexical_updates = array();

	/**
	 * Tracks open elements while scanning XML.
	 *
	 * @since WP_VERSION
	 *
	 * @var string[]
	 */
	public $stack_of_open_elements = array();

	/**
	 * Tracks and limits `seek()` calls to prevent accidental infinite loops.
	 *
	 * @since WP_VERSION
	 * @var int
	 *
	 * @see WP_XML_Tag_Processor::seek()
	 */
	protected $seek_count = 0;

	/**
	 * Constructor.
	 *
	 * @since WP_VERSION
	 *
	 * @param string $xml XML to process.
	 */
	public function __construct( $xml ) {
		$this->xml = $xml;
	}

	/**
	 * Finds the next tag matching the $query.
	 *
	 * @since WP_VERSION
	 * @since WP_VERSION No longer processes incomplete tokens at end of document; pauses the processor at start of token.
	 *
	 * @param array|string|null $query {
	 *     Optional. Which tag name to find, having which class, etc. Default is to find any tag.
	 *
	 *     @type string|null $tag_name     Which tag to find, or `null` for "any tag."
	 *     @type int|null    $match_offset Find the Nth tag matching all search criteria.
	 *                                     1 for "first" tag, 3 for "third," etc.
	 *                                     Defaults to first tag.
	 *     @type string|null $class_name   Tag must contain this whole class name to match.
	 *     @type string|null $tag_closers  "visit" or "skip": whether to stop on tag closers, e.g. </div>.
	 * }
	 * @return bool Whether a tag was matched.
	 */
	public function next_tag( $query = null ) {
		$this->parse_query( $query );
		$already_found = 0;

		do {
			if ( false === $this->next_token() ) {
				return false;
			}

			if ( self::STATE_MATCHED_TAG !== $this->parser_state ) {
				continue;
			}

			if ( $this->matches() ) {
				++$already_found;
			}
		} while ( $already_found < $this->sought_match_offset );

		return true;
	}

	/**
	 * Finds the next token in the XML document.
	 *
	 * An XML document can be viewed as a stream of tokens,
	 * where tokens are things like XML tags, XML comments,
	 * text nodes, etc. This method finds the next token in
	 * the XML document and returns whether it found one.
	 *
	 * If it starts parsing a token and reaches the end of the
	 * document then it will seek to the start of the last
	 * token and pause, returning `false` to indicate that it
	 * failed to find a complete token.
	 *
	 * Possible token types, based on the XML specification:
	 *
	 *  - an XML tag, whether opening, closing, or void.
	 *  - a text node - the plaintext inside tags.
	 *  - an XML comment.
	 *  - a DOCTYPE declaration.
	 *  - a processing instruction, e.g. `<?xml version="1.0" ?>`.
	 *
	 * The Tag Processor currently only supports the tag token.
	 *
	 * @since WP_VERSION
	 *
	 * @return bool Whether a token was parsed.
	 */
	public function next_token() {
		return $this->base_class_next_token();
	}

	/**
	 * Internal method which finds the next token in the XML document.
	 *
	 * This method is a protected internal function which implements the logic for
	 * finding the next token in a document. It exists so that the parser can update
	 * its state without affecting the location of the cursor in the document and
	 * without triggering subclass methods for things like `next_token()`, e.g. when
	 * applying patches before searching for the next token.
	 *
	 * @since WP_VERSION
	 *
	 * @access private
	 *
	 * @return bool Whether a token was parsed.
	 */
	private function base_class_next_token() {
		$was_at = $this->bytes_already_parsed;
		$this->after_tag();

		// Don't proceed if there's nothing more to scan.
		if (
			self::STATE_COMPLETE === $this->parser_state ||
			self::STATE_INCOMPLETE_INPUT === $this->parser_state ||
			self::STATE_INVALID_INPUT === $this->parser_state
		) {
			return false;
		}

		/*
		 * The next step in the parsing loop determines the parsing state;
		 * clear it so that state doesn't linger from the previous step.
		 */
		$this->parser_state = self::STATE_READY;

		if ( $this->bytes_already_parsed >= strlen( $this->xml ) ) {
			$this->parser_state = self::STATE_COMPLETE;
			return false;
		}

		// Find the next tag if it exists.
		if ( false === $this->parse_next_tag() ) {
			if ( self::STATE_INCOMPLETE_INPUT === $this->parser_state ) {
				$this->bytes_already_parsed = $was_at;
			}

			return false;
		}

		if ( self::STATE_INVALID_INPUT === $this->parser_state ) {
			return false;
		}

		/*
		 * For legacy reasons the rest of this function handles tags and their
		 * attributes. If the processor has reached the end of the document
		 * or if it matched any other token then it should return here to avoid
		 * attempting to process tag-specific syntax.
		 */
		if (
			self::STATE_INCOMPLETE_INPUT !== $this->parser_state &&
			self::STATE_COMPLETE !== $this->parser_state &&
			self::STATE_MATCHED_TAG !== $this->parser_state
		) {
			return true;
		}

		if ( $this->is_closing_tag ) {
			$this->skip_whitespace();
		} else {
			// Parse all of its attributes.
			while ( $this->parse_next_attribute() ) {
				continue;
			}
		}

		if ( self::STATE_INVALID_INPUT === $this->parser_state ) {
			return false;
		}

		// Ensure that the tag closes before the end of the document.
		if (
			self::STATE_INCOMPLETE_INPUT === $this->parser_state ||
			$this->bytes_already_parsed >= strlen( $this->xml )
		) {
			// Does this appropriately clear state (parsed attributes)?
			$this->parser_state         = self::STATE_INCOMPLETE_INPUT;
			$this->bytes_already_parsed = $was_at;

			return false;
		}

		$tag_ends_at = strpos( $this->xml, '>', $this->bytes_already_parsed );
		if ( false === $tag_ends_at ) {
			$this->parser_state         = self::STATE_INCOMPLETE_INPUT;
			$this->bytes_already_parsed = $was_at;

			return false;
		}

		if ( $this->is_closing_tag && $tag_ends_at !== $this->bytes_already_parsed ) {
			$this->parser_state = self::STATE_INVALID_INPUT;
			_doing_it_wrong(
				__METHOD__,
				__( "Invalid closing tag encountered." ),
				'WP_VERSION'
			);
			return false;
		}

		$this->parser_state         = self::STATE_MATCHED_TAG;
		$this->bytes_already_parsed = $tag_ends_at + 1;
		$this->token_length         = $this->bytes_already_parsed - $this->token_starts_at;
		return true;
	}

	/**
	 * Whether the processor paused because the input XML document ended
	 * in the middle of a syntax element, such as in the middle of a tag.
	 *
	 * Example:
	 *
	 *     $processor = new WP_XML_Tag_Processor( '<input type="text" value="Th' );
	 *     false      === $processor->get_next_tag();
	 *     true       === $processor->paused_at_incomplete_token();
	 *
	 * @since WP_VERSION
	 *
	 * @return bool Whether the parse paused at the start of an incomplete token.
	 */
	public function paused_at_incomplete_token() {
		return self::STATE_INCOMPLETE_INPUT === $this->parser_state;
	}

	/**
	 * Sets a bookmark in the XML document.
	 *
	 * Bookmarks represent specific places or tokens in the XML
	 * document, such as a tag opener or closer. When applying
	 * edits to a document, such as setting an attribute, the
	 * text offsets of that token may shift; the bookmark is
	 * kept updated with those shifts and remains stable unless
	 * the entire span of text in which the token sits is removed.
	 *
	 * Release bookmarks when they are no longer needed.
	 *
	 * Example:
	 *
	 *     <main><h2>Surprising fact you may not know!</h2></main>
	 *           ^  ^
	 *            \-|-- this `H2` opener bookmark tracks the token
	 *
	 *     <main class="clickbait"><h2>Surprising fact you may no…
	 *                             ^  ^
	 *                              \-|-- it shifts with edits
	 *
	 * Bookmarks provide the ability to seek to a previously-scanned
	 * place in the XML document. This avoids the need to re-scan
	 * the entire document.
	 *
	 * Example:
	 *
	 *     <ul><li>One</li><li>Two</li><li>Three</li></ul>
	 *                                 ^^^^
	 *                                 want to note this last item
	 *
	 *     $p = new WP_XML_Tag_Processor( $xml );
	 *     $in_list = false;
	 *     while ( $p->next_tag( array( 'tag_closers' => $in_list ? 'visit' : 'skip' ) ) ) {
	 *         if ( 'UL' === $p->get_tag() ) {
	 *             if ( $p->is_tag_closer() ) {
	 *                 $in_list = false;
	 *                 $p->set_bookmark( 'resume' );
	 *                 if ( $p->seek( 'last-li' ) ) {
	 *                     $p->add_class( 'last-li' );
	 *                 }
	 *                 $p->seek( 'resume' );
	 *                 $p->release_bookmark( 'last-li' );
	 *                 $p->release_bookmark( 'resume' );
	 *             } else {
	 *                 $in_list = true;
	 *             }
	 *         }
	 *
	 *         if ( 'LI' === $p->get_tag() ) {
	 *             $p->set_bookmark( 'last-li' );
	 *         }
	 *     }
	 *
	 * Bookmarks intentionally hide the internal string offsets
	 * to which they refer. They are maintained internally as
	 * updates are applied to the XML document and therefore
	 * retain their "position" - the location to which they
	 * originally pointed. The inability to use bookmarks with
	 * functions like `substr` is therefore intentional to guard
	 * against accidentally breaking the XML.
	 *
	 * Because bookmarks allocate memory and require processing
	 * for every applied update, they are limited and require
	 * a name. They should not be created with programmatically-made
	 * names, such as "li_{$index}" with some loop. As a general
	 * rule they should only be created with string-literal names
	 * like "start-of-section" or "last-paragraph".
	 *
	 * Bookmarks are a powerful tool to enable complicated behavior.
	 * Consider double-checking that you need this tool if you are
	 * reaching for it, as inappropriate use could lead to broken
	 * XML structure or unwanted processing overhead.
	 *
	 * @since WP_VERSION
	 *
	 * @param string $name Identifies this particular bookmark.
	 * @return bool Whether the bookmark was successfully created.
	 */
	public function set_bookmark( $name ) {
		// It only makes sense to set a bookmark if the parser has paused on a concrete token.
		if (
			self::STATE_COMPLETE === $this->parser_state ||
			self::STATE_INCOMPLETE_INPUT === $this->parser_state
		) {
			return false;
		}

		if ( ! array_key_exists( $name, $this->bookmarks ) && count( $this->bookmarks ) >= static::MAX_BOOKMARKS ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'Too many bookmarks: cannot create any more.' ),
				'WP_VERSION'
			);
			return false;
		}

		$this->bookmarks[ $name ] = new WP_HTML_Span( $this->token_starts_at, $this->token_length );

		return true;
	}


	/**
	 * Removes a bookmark that is no longer needed.
	 *
	 * Releasing a bookmark frees up the small
	 * performance overhead it requires.
	 *
	 * @param string $name Name of the bookmark to remove.
	 * @return bool Whether the bookmark already existed before removal.
	 */
	public function release_bookmark( $name ) {
		if ( ! array_key_exists( $name, $this->bookmarks ) ) {
			return false;
		}

		unset( $this->bookmarks[ $name ] );

		return true;
	}

	/**
	 * Skips contents of generic rawtext elements.
	 *
	 * @since 6.3.2
	 *
	 * @see https://xml.spec.whatwg.org/#generic-raw-text-element-parsing-algorithm
	 *
	 * @param string $tag_name The uppercase tag name which will close the RAWTEXT region.
	 * @return bool Whether an end to the RAWTEXT region was found before the end of the document.
	 */
	private function skip_rawtext( $tag_name ) {
		/*
		 * These two functions distinguish themselves on whether character references are
		 * decoded, and since functionality to read the inner markup isn't supported, it's
		 * not necessary to implement these two functions separately.
		 */
		return $this->skip_rcdata( $tag_name );
	}

	/**
	 * Skips contents of RCDATA elements, namely title and textarea tags.
	 *
	 * @since WP_VERSION
	 *
	 * @see https://xml.spec.whatwg.org/multipage/parsing.xml#rcdata-state
	 *
	 * @param string $tag_name The uppercase tag name which will close the RCDATA region.
	 * @return bool Whether an end to the RCDATA region was found before the end of the document.
	 */
	private function skip_rcdata( $tag_name ) {
		$xml       = $this->xml;
		$doc_length = strlen( $xml );
		$tag_length = strlen( $tag_name );

		$at = $this->bytes_already_parsed;

		while ( false !== $at && $at < $doc_length ) {
			$at                       = strpos( $this->xml, '</', $at );
			$this->tag_name_starts_at = $at;

			// Fail if there is no possible tag closer.
			if ( false === $at || ( $at + $tag_length ) >= $doc_length ) {
				return false;
			}

			$at += 2;

			/*
			 * Find a case-sensitive match to the tag name.
			 *
			 * Because tag names are limited to US-ASCII there is no
			 * need to perform any kind of Unicode normalization when
			 * comparing; any character which could be impacted by such
			 * normalization could not be part of a tag name.
			 */
			for ( $i = 0; $i < $tag_length; $i++ ) {
				if ( $xml[ $at + $i ] !== $tag_name[ $i ] ) {
					$at += $i;
					continue 2;
				}
			}

			$at                        += $tag_length;
			$this->bytes_already_parsed = $at;

			if ( $at >= strlen( $xml ) ) {
				return false;
			}

			/*
			 * Ensure that the tag name terminates to avoid matching on
			 * substrings of a longer tag name. For example, the sequence
			 * "</textarearug" should not match for "</textarea" even
			 * though "textarea" is found within the text.
			 */
			$c = $xml[ $at ];
			if ( ' ' !== $c && "\t" !== $c && "\r" !== $c && "\n" !== $c && '/' !== $c && '>' !== $c ) {
				continue;
			}

			while ( $this->parse_next_attribute() ) {
				continue;
			}

			$at = $this->bytes_already_parsed;
			if ( $at >= strlen( $this->xml ) ) {
				return false;
			}

			if ( '>' === $xml[ $at ] ) {
				$this->bytes_already_parsed = $at + 1;
				return true;
			}

			if ( $at + 1 >= strlen( $this->xml ) ) {
				return false;
			}

			if ( '/' === $xml[ $at ] && '>' === $xml[ $at + 1 ] ) {
				$this->bytes_already_parsed = $at + 2;
				return true;
			}
		}

		return false;
	}

	private $is_implicit_cdata_element;
	public function treat_element_contents_as_cdata()
	{
		if (
			self::STATE_MATCHED_TAG !== $this->parser_state ||
			$this->get_token_type() ||
			$this->is_empty_element_tag()
		) {

			_doing_it_wrong(
				__METHOD__,
				__( 'treat_element_contents_as_cdata can only be called after matching a tag.' ),
				'WP_VERSION'
			);
			return false;
		}
		$this->is_implicit_cdata_element = true;
		return true;
	}

	/**
	 * Parses the next tag.
	 *
	 * This will find and start parsing the next tag, including
	 * the opening `<`, the potential closer `/`, and the tag
	 * name. It does not parse the attributes or scan to the
	 * closing `>`; these are left for other methods.
	 *
	 * @since WP_VERSION
	 * @since 6.2.1 Support abruptly-closed comments, invalid-tag-closer-comments, and empty elements.
	 *
	 * @return bool Whether a tag was found before the end of the document.
	 */
	private function parse_next_tag() {
		$this->after_tag();
	
		$xml        = $this->xml;
		$doc_length = strlen( $xml );
		$was_at     = $this->bytes_already_parsed;
		$at         = $was_at;

		while ( false !== $at && $at < $doc_length ) {
			// if ( $is_implicit_cdata ) {
			// 	$closer_at = strpos($xml, '</' . $tag_name, $at);
			// 	if (false === $closer_at) {
			// 		$this->parser_state = self::STATE_INCOMPLETE_INPUT;
			// 		return false;
			// 	}

			// 	$this->bytes_already_parsed = $closer_at + 2 + strlen($tag_name);
			// 	$this->skip_whitespace();
			// 	if('>' !== $this->xml[$this->bytes_already_parsed]) {
			// 		continue;
			// 	}
			// }

			$at = strpos( $xml, '<', $at );

			/*
			 * This does not imply an incomplete parse; it indicates that there
			 * can be nothing left in the document other than a #text node.
			 */
			if ( false === $at ) {
				$this->parser_state         = self::STATE_TEXT_NODE;
				$this->token_starts_at      = $was_at;
				$this->token_length         = strlen( $xml ) - $was_at;
				$this->text_starts_at       = $was_at;
				$this->text_length          = $this->token_length;
				$this->bytes_already_parsed = strlen( $xml );
				return true;
			}

			if ( $at > $was_at ) {
				$this->parser_state         = self::STATE_TEXT_NODE;
				$this->token_starts_at      = $was_at;
				$this->token_length         = $at - $was_at;
				$this->text_starts_at       = $was_at;
				$this->text_length          = $this->token_length;
				$this->bytes_already_parsed = $at;
				return true;
			}

			$this->token_starts_at = $at;

			if ( $at + 1 < $doc_length && '/' === $this->xml[ $at + 1 ] ) {
				$this->is_closing_tag = true;
				++$at;
			} else {
				$this->is_closing_tag = false;
			}

			/*
			 * XML tag names must start with [a-zA-Z] otherwise they are not tags.
			 * For example, "<3" is rendered as text, not a tag opener. If at least
			 * one letter follows the "<" then _it is_ a tag, but if the following
			 * character is anything else it _is not a tag_.
			 *
			 * It's not uncommon to find non-tags starting with `<` in an XML
			 * document, so it's good for performance to make this pre-check before
			 * continuing to attempt to parse a tag name.
			 *
			 * Reference:
			 * * https://xml.spec.whatwg.org/multipage/parsing.xml#data-state
			 * * https://xml.spec.whatwg.org/multipage/parsing.xml#tag-open-state
			 */
			$tag_name_length = $this->parse_name( $at + 1 );
			if ( $tag_name_length > 0 ) {
				++$at;
				$this->parser_state         = self::STATE_MATCHED_TAG;
				$this->tag_name_starts_at   = $at;
				$this->tag_name_length      = $tag_name_length;
				$this->bytes_already_parsed = $at + $this->tag_name_length;

				if ( $this->is_closing_tag ) {
					$popped = array_pop( $this->stack_of_open_elements );
					if($popped !== $this->get_tag()) {
						$this->parser_state = self::STATE_INVALID_INPUT;
						_doing_it_wrong(
							__METHOD__,
							__( 'The closing tag did not match the opening tag.' ),
							'WP_VERSION'
						);
						return false;
					}					
				} else {
					array_push(
						$this->stack_of_open_elements,
						$this->get_tag()
					);
				}
				return true;
			}

			/*
			 * Abort if no tag is found before the end of
			 * the document. There is nothing left to parse.
			 */
			if ( $at + 1 >= $doc_length ) {
				$this->parser_state = self::STATE_INCOMPLETE_INPUT;

				return false;
			}

			/*
			 * `<!` transitions to markup declaration open state
			 * https://xml.spec.whatwg.org/multipage/parsing.xml#markup-declaration-open-state
			 */
			if ( ! $this->is_closing_tag && '!' === $xml[ $at + 1 ] ) {
				/*
				 * `<!--` mark a beginning of a comment.
				 * https://www.w3.org/TR/xml/#sec-comments
				 */
				if (
					$doc_length > $at + 3 &&
					'-' === $xml[ $at + 2 ] &&
					'-' === $xml[ $at + 3 ]
				) {
					$closer_at = $at + 4;
					// If it's not possible to close the comment then there is nothing more to scan.
					if ( $doc_length <= $closer_at ) {
						$this->parser_state = self::STATE_INCOMPLETE_INPUT;

						return false;
					}

					/*
					 * Comments may only be closed by a --> sequence.
					 */
					--$closer_at; // Pre-increment inside condition below reduces risk of accidental infinite looping.
					while ( ++$closer_at < $doc_length ) {
						$closer_at = strpos( $xml, '--', $closer_at );
						if ( false === $closer_at || $closer_at + 2 == $doc_length ) {
							$this->parser_state = self::STATE_INCOMPLETE_INPUT;
							return false;
						}

						/*
						 * The string " -- " (double-hyphen) must not occur within comments
						 * See https://www.w3.org/TR/xml/#sec-comments
						 */
						if ( '>' !== $xml[ $closer_at + 2 ] ) {
							$this->parser_state = self::STATE_INVALID_INPUT;
							_doing_it_wrong(
								__METHOD__,
								__( 'Invalid comment syntax encountered.' ),
								'WP_VERSION'
							);
							return false;
						}

						$this->parser_state         = self::STATE_COMMENT;
						$this->comment_type         = self::COMMENT_AS_XML_COMMENT;
						$this->token_length         = $closer_at + 3 - $this->token_starts_at;
						$this->text_starts_at       = $this->token_starts_at + 4;
						$this->text_length          = $closer_at - $this->text_starts_at;
						$this->bytes_already_parsed = $closer_at + 3;
						return true;
					}
				}

				/*
				 * Identify CDATA sections.
				 *
				 * Within a CDATA section, everything until the ]]> string is treated
				 * as data, not markup. Left angle brackets and ampersands may occur in
				 * their literal form; they need not (and cannot) be escaped using "&lt;"
				 * and "&amp;". CDATA sections cannot nest.
				 *
				 * See https://www.w3.org/TR/xml11.xml/#sec-cdata-sect
				 */
				if (
					! $this->is_closing_tag &&
					$doc_length > $this->token_starts_at + 8 &&
					'[' === $xml[ $this->token_starts_at + 2 ] &&
					'C' === $xml[ $this->token_starts_at + 3 ] &&
					'D' === $xml[ $this->token_starts_at + 4 ] &&
					'A' === $xml[ $this->token_starts_at + 5 ] &&
					'T' === $xml[ $this->token_starts_at + 6 ] &&
					'A' === $xml[ $this->token_starts_at + 7 ] &&
					'[' === $xml[ $this->token_starts_at + 8 ]
				) {
					$closer_at = strpos( $xml, ']]>', $at + 1 );
					if ( false === $closer_at ) {
						$this->parser_state = self::STATE_INCOMPLETE_INPUT;

						return false;
					}

					$this->parser_state         = self::STATE_CDATA_NODE;
					$this->token_length         = $closer_at + 1 - $this->token_starts_at;
					$this->text_starts_at       = $this->token_starts_at + 9;
					$this->text_length          = $closer_at - $this->text_starts_at;
					$this->bytes_already_parsed = $closer_at + 3;
					return true;
				}

				/*
				 * Anything else here is either unsupported at this point or invalid
				 * syntax. See the class-level @TODO annotations for more information.
				 */
				$this->parser_state = self::STATE_INCOMPLETE_INPUT;

				return false;
			}

			/*
			 * An `<?xml` token at the beginning of the document marks a start of an
			 * xml declaration.
			 * See https://www.w3.org/TR/xml/#sec-prolog-dtd
			 */
			if (
				$at === 0 &&
				! $this->is_closing_tag &&
				'?' === $xml[ $at + 1 ] &&
				'x' === $xml[ $at + 2 ] &&
				'm' === $xml[ $at + 3 ] &&
				'l' === $xml[ $at + 4 ]
			) {
				// Setting the parser state early for the get_attribute() calls later in this
				// branch.
				$this->parser_state = self::STATE_XML_DECLARATION;

				$at += 5;

				// Skip whitespace.
				$at += strspn( $this->xml, " \t\f\r\n", $at );

				$this->bytes_already_parsed = $at;

				/*
				 * Reuse parse_next_attribute() to parse the XML declaration attributes.
				 * Technically, only "version", "encoding", and "standalone" are accepted
				 * and, unlike regular tag attributes, their values can contain any character
				 * other than the opening quote. However, the "<" and "&" characters are very
				 * unlikely to be encountered and cause trouble, so this code path liberally
				 * does not provide a dedicated parsing logic.
				 */
				while ( false !== $this->parse_next_attribute() ) {
					$this->skip_whitespace();
					// Parse until the XML declaration closer.
					if ( $xml[$this->bytes_already_parsed] === '?' ) {
						break;
					}
				}

				if ( self::STATE_INVALID_INPUT === $this->parser_state ) {
					return false;
				}

				foreach ( $this->attributes as $name => $attribute ) {
					if ( 'version' !== $name && 'encoding' !== $name && 'standalone' !== $name ) {
						$this->parser_state = self::STATE_INVALID_INPUT;
						_doing_it_wrong(
							__METHOD__,
							__( 'Invalid attribute found in XML declaration.' ),
							'WP_VERSION'
						);
						return false;
					}
				}

				if ( '1.0' !== $this->get_attribute( 'version' ) ) {
					$this->parser_state = self::STATE_INVALID_INPUT;
					_doing_it_wrong(
						__METHOD__,
						__( 'Unsupported XML version declared' ),
						'WP_VERSION'
					);
					return false;
				}

				$at = $this->bytes_already_parsed;

				// Skip whitespace.
				$at += strspn( $this->xml, " \t\f\r\n", $at );

				// Consume the closer.
				if ( ! (
					$at + 2 <= $doc_length &&
					'?' === $xml[ $at ] &&
					'>' === $xml[ $at + 1 ]
				) ) {
					$this->parser_state = self::STATE_INVALID_INPUT;
					_doing_it_wrong(
						__METHOD__,
						__( 'XML declaration closer not found.' ),
						'WP_VERSION'
					);
					return false;
				}

				$this->token_length         = $at + 2 - $this->token_starts_at;
				$this->text_starts_at       = $this->token_starts_at + 2;
				$this->text_length          = $at - $this->text_starts_at;
				$this->bytes_already_parsed = $at + 2;
				$this->parser_state = self::STATE_XML_DECLARATION;

				return true;
			}

			/*
			 * `<?` denotes a processing instruction.
			 * See https://www.w3.org/TR/xml/#sec-pi
			 */
			if ( 
				! $this->is_closing_tag &&
				'?' === $xml[ $at + 1 ]
			) {
				if ( ! (
					$at + 4 <= $doc_length &&
					( 'x' === $xml[ $at + 2 ] || 'X' === $xml[ $at + 2 ] ) &&
					( 'm' === $xml[ $at + 3 ] || 'M' === $xml[ $at + 3 ] ) &&
					( 'l' === $xml[ $at + 4 ] || 'L' === $xml[ $at + 4 ] )
				) ) {
					_doing_it_wrong(
						__METHOD__,
						__( 'Invalid processing instruction target.' ),
						'WP_VERSION'
					);
					return false;
				}

				$at += 5;

				// Skip whitespace.
				$this->skip_whitespace();

				/*
				 * Find the closer.
				 *
				 * We could, at this point, only consume the bytes allowed by the specification, that is:
				 *
				 * [2] Char ::= #x9 | #xA | #xD | [#x20-#xD7FF] | [#xE000-#xFFFD] | [#x10000-#x10FFFF] // any Unicode character, excluding the surrogate blocks, FFFE, and FFFF.
				 *
				 * However, that would require running a slow regular-expression engine for, seemingly,
				 * little benefit. For now, we are going to pretend that all bytes are allowed until the
				 * closing ?> is found. Some failures may pass unnoticed. That may not be a problem in practice,
				 * but if it is then this code path will require a stricter implementation.
				 */
				$closer_at = strpos( $xml, '?>', $at );
				if ( false === $closer_at ) {
					$this->parser_state = self::STATE_INCOMPLETE_INPUT;

					return false;
				}

				$this->parser_state         = self::STATE_PI_NODE;
				$this->token_length         = $closer_at + 5 - $this->token_starts_at;
				$this->text_starts_at       = $this->token_starts_at + 5;
				$this->text_length          = $closer_at - $this->text_starts_at;
				$this->bytes_already_parsed = $closer_at + 2;

				return true;
			}

			++$at;
		}

		return false;
	}

	/**
	 * Parses the next attribute.
	 *
	 * @since WP_VERSION
	 *
	 * @return bool Whether an attribute was found before the end of the document.
	 */
	private function parse_next_attribute() {
		// Skip whitespace and slashes.
		$this->bytes_already_parsed += strspn( $this->xml, " \t\f\r\n/", $this->bytes_already_parsed );
		if ( $this->bytes_already_parsed >= strlen( $this->xml ) ) {
			$this->parser_state = self::STATE_INCOMPLETE_INPUT;

			return false;
		}

		// No more attributes to parse.
		if ( $this->xml[ $this->bytes_already_parsed ] === '>' ) {
			return false;
		}

		$attribute_start       = $this->bytes_already_parsed;
		$attribute_name_length = $this->parse_name();
		if($attribute_name_length === 0) {
			$this->parser_state = self::STATE_INVALID_INPUT;
			_doing_it_wrong(
				__METHOD__,
				__( 'Invalid attribute name encountered.' ),
				'WP_VERSION'
			);
		}
		$this->bytes_already_parsed += $attribute_name_length;
		$attribute_name        = substr( $this->xml, $attribute_start, $attribute_name_length );
		$this->skip_whitespace();

		// Parse attribute value.
		++$this->bytes_already_parsed;
		$this->skip_whitespace();
		if ( $this->bytes_already_parsed >= strlen( $this->xml ) ) {
			$this->parser_state = self::STATE_INCOMPLETE_INPUT;

			return false;
		}
		switch ( $this->xml[ $this->bytes_already_parsed ] ) {
			case "'":
			case '"':
				$quote         = $this->xml[ $this->bytes_already_parsed ];
				$value_start   = $this->bytes_already_parsed + 1;
				$value_length  = strcspn( $this->xml, "<&$quote", $value_start );
				$attribute_end = $value_start + $value_length + 1;

				if ( $this->xml[ $attribute_end - 1 ] !== $quote ) {
					$this->parser_state = self::STATE_INVALID_INPUT;
					_doing_it_wrong(
						__METHOD__,
						__( 'A disallowed character encountered in an attribute value (either < or &).' ),
						'WP_VERSION'
					);
				}
				$this->bytes_already_parsed = $attribute_end;
				break;

			default:
				$this->parser_state = self::STATE_INVALID_INPUT;
				_doing_it_wrong(
					__METHOD__,
					__( 'Unquoted attribute value encountered.' ),
					'WP_VERSION'
				);
				return false;
		}

		if ( $attribute_end >= strlen( $this->xml ) ) {
			$this->parser_state = self::STATE_INCOMPLETE_INPUT;
			return false;
		}

		if ( $this->is_closing_tag ) {
			return true;
		}

		if ( array_key_exists( $attribute_name, $this->attributes ) ) {
			$this->parser_state = self::STATE_INVALID_INPUT;
			_doing_it_wrong(
				__METHOD__,
				__( 'Duplicate attribute found in an XML tag.' ),
				'WP_VERSION'
			);
			return false;
		}

		$this->attributes[ $attribute_name ] = new WP_HTML_Attribute_Token(
			$attribute_name,
			$value_start,
			$value_length,
			$attribute_start,
			$attribute_end - $attribute_start,
			false
		);

		return true;
	}

	/**
	 * Move the internal cursor past any immediate successive whitespace.
	 *
	 * @since WP_VERSION
	 */
	private function skip_whitespace() {
		$this->bytes_already_parsed += strspn( $this->xml, " \t\f\r\n", $this->bytes_already_parsed );
	}

	// Describes the first character of the attribute name:
	// NameStartChar ::= ":" | [A-Z] | "_" | [a-z] | [#xC0-#xD6] | [#xD8-#xF6] | [#xF8-#x2FF] | [#x370-#x37D] | [#x37F-#x1FFF] | [#x200C-#x200D] | [#x2070-#x218F] | [#x2C00-#x2FEF] | [#x3001-#xD7FF] | [#xF900-#xFDCF] | [#xFDF0-#xFFFD] | [#x10000-#xEFFFF]
	// See https://www.w3.org/TR/xml/#NT-Name
	const NAME_START_CHAR_PATTERN = ':a-z_A-Z\x{C0}-\x{D6}\x{D8}-\x{F6}\x{F8}-\x{2FF}\x{370}-\x{37D}\x{37F}-\x{1FFF}\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}';
	const NAME_CHAR_PATTERN = '\-\.0-9\x{B7}\x{0300}-\x{036F}\x{203F}-\x{2040}:a-z_A-Z\x{C0}-\x{D6}\x{D8}-\x{F6}\x{F8}-\x{2FF}\x{370}-\x{37D}\x{37F}-\x{1FFF}\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}';
	private function parse_name($offset = null) {
		if($offset === null) {
			$offset = $this->bytes_already_parsed;
		}
		if ( 1 !== preg_match(
			'~[' . self::NAME_START_CHAR_PATTERN . ']~Ssu',
			$this->xml[ $offset ],
			$matches
		) ) {
			return 0;
		}

		$name_length = 1;

		// Consume the rest of the name
		preg_match(
			'~\G([' . self::NAME_CHAR_PATTERN . ']+)~Ssu',
			$this->xml,
			$matches,
			0,
			$offset + 1
		);

		if ( is_array($matches) && count($matches) > 0 ) {
			$name_length += strlen( $matches[0] );
		}

		return $name_length;
	}

	/**
	 * Applies attribute updates and cleans up once a tag is fully parsed.
	 *
	 * @since WP_VERSION
	 */
	private function after_tag() {
		/*
		 * Purge updates if there are too many. The actual count isn't
		 * scientific, but a few values from 100 to a few thousand were
		 * tests to find a practically-useful limit.
		 *
		 * If the update queue grows too big, then the Tag Processor
		 * will spend more time iterating through them and lose the
		 * efficiency gains of deferring applying them.
		 */
		if ( 1000 < count( $this->lexical_updates ) ) {
			$this->get_updated_xml();
		}

		foreach ( $this->lexical_updates as $name => $update ) {
			/*
			 * Any updates appearing after the cursor should be applied
			 * before proceeding, otherwise they may be overlooked.
			 */
			if ( $update->start >= $this->bytes_already_parsed ) {
				$this->get_updated_xml();
				break;
			}

			if ( is_int( $name ) ) {
				continue;
			}

			$this->lexical_updates[] = $update;
			unset( $this->lexical_updates[ $name ] );
		}

		$this->token_starts_at      = null;
		$this->token_length         = null;
		$this->tag_name_starts_at   = null;
		$this->tag_name_length      = null;
		$this->text_starts_at       = 0;
		$this->text_length          = 0;
		$this->is_closing_tag       = null;
		$this->attributes           = array();
		$this->comment_type         = null;
	}

	/**
	 * Applies attribute updates to XML document.
	 *
	 * @since WP_VERSION
	 * @since 6.2.1 Accumulates shift for internal cursor and passed pointer.
	 * @since WP_VERSION Invalidate any bookmarks whose targets are overwritten.
	 *
	 * @param int $shift_this_point Accumulate and return shift for this position.
	 * @return int How many bytes the given pointer moved in response to the updates.
	 */
	private function apply_attributes_updates( $shift_this_point = 0 ) {
		if ( ! count( $this->lexical_updates ) ) {
			return 0;
		}

		$accumulated_shift_for_given_point = 0;

		/*
		 * Attribute updates can be enqueued in any order but updates
		 * to the document must occur in lexical order; that is, each
		 * replacement must be made before all others which follow it
		 * at later string indices in the input document.
		 *
		 * Sorting avoid making out-of-order replacements which
		 * can lead to mangled output, partially-duplicated
		 * attributes, and overwritten attributes.
		 */
		usort( $this->lexical_updates, array( self::class, 'sort_start_ascending' ) );

		$bytes_already_copied = 0;
		$output_buffer        = '';
		foreach ( $this->lexical_updates as $diff ) {
			$shift = strlen( $diff->text ) - $diff->length;

			// Adjust the cursor position by however much an update affects it.
			if ( $diff->start < $this->bytes_already_parsed ) {
				$this->bytes_already_parsed += $shift;
			}

			// Accumulate shift of the given pointer within this function call.
			if ( $diff->start <= $shift_this_point ) {
				$accumulated_shift_for_given_point += $shift;
			}

			$output_buffer       .= substr( $this->xml, $bytes_already_copied, $diff->start - $bytes_already_copied );
			$output_buffer       .= $diff->text;
			$bytes_already_copied = $diff->start + $diff->length;
		}

		$this->xml = $output_buffer . substr( $this->xml, $bytes_already_copied );

		/*
		 * Adjust bookmark locations to account for how the text
		 * replacements adjust offsets in the input document.
		 */
		foreach ( $this->bookmarks as $bookmark_name => $bookmark ) {
			$bookmark_end = $bookmark->start + $bookmark->length;

			/*
			 * Each lexical update which appears before the bookmark's endpoints
			 * might shift the offsets for those endpoints. Loop through each change
			 * and accumulate the total shift for each bookmark, then apply that
			 * shift after tallying the full delta.
			 */
			$head_delta = 0;
			$tail_delta = 0;

			foreach ( $this->lexical_updates as $diff ) {
				$diff_end = $diff->start + $diff->length;

				if ( $bookmark->start < $diff->start && $bookmark_end < $diff->start ) {
					break;
				}

				if ( $bookmark->start >= $diff->start && $bookmark_end < $diff_end ) {
					$this->release_bookmark( $bookmark_name );
					continue 2;
				}

				$delta = strlen( $diff->text ) - $diff->length;

				if ( $bookmark->start >= $diff->start ) {
					$head_delta += $delta;
				}

				if ( $bookmark_end >= $diff_end ) {
					$tail_delta += $delta;
				}
			}

			$bookmark->start  += $head_delta;
			$bookmark->length += $tail_delta - $head_delta;
		}

		$this->lexical_updates = array();

		return $accumulated_shift_for_given_point;
	}

	/**
	 * Checks whether a bookmark with the given name exists.
	 *
	 * @since WP_VERSION
	 *
	 * @param string $bookmark_name Name to identify a bookmark that potentially exists.
	 * @return bool Whether that bookmark exists.
	 */
	public function has_bookmark( $bookmark_name ) {
		return array_key_exists( $bookmark_name, $this->bookmarks );
	}

	/**
	 * Move the internal cursor in the Tag Processor to a given bookmark's location.
	 *
	 * In order to prevent accidental infinite loops, there's a
	 * maximum limit on the number of times seek() can be called.
	 *
	 * @since WP_VERSION
	 *
	 * @param string $bookmark_name Jump to the place in the document identified by this bookmark name.
	 * @return bool Whether the internal cursor was successfully moved to the bookmark's location.
	 */
	public function seek( $bookmark_name ) {
		if ( ! array_key_exists( $bookmark_name, $this->bookmarks ) ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'Unknown bookmark name.' ),
				'WP_VERSION'
			);
			return false;
		}

		if ( ++$this->seek_count > static::MAX_SEEK_OPS ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'Too many calls to seek() - this can lead to performance issues.' ),
				'WP_VERSION'
			);
			return false;
		}

		// Flush out any pending updates to the document.
		$this->get_updated_xml();

		// Point this tag processor before the sought tag opener and consume it.
		$this->bytes_already_parsed = $this->bookmarks[ $bookmark_name ]->start;
		$this->parser_state         = self::STATE_READY;
		return $this->next_token();
	}

	/**
	 * Compare two WP_HTML_Text_Replacement objects.
	 *
	 * @since WP_VERSION
	 *
	 * @param WP_HTML_Text_Replacement $a First attribute update.
	 * @param WP_HTML_Text_Replacement $b Second attribute update.
	 * @return int Comparison value for string order.
	 */
	private static function sort_start_ascending( $a, $b ) {
		$by_start = $a->start - $b->start;
		if ( 0 !== $by_start ) {
			return $by_start;
		}

		$by_text = isset( $a->text, $b->text ) ? strcmp( $a->text, $b->text ) : 0;
		if ( 0 !== $by_text ) {
			return $by_text;
		}

		/*
		 * This code should be unreachable, because it implies the two replacements
		 * start at the same location and contain the same text.
		 */
		return $a->length - $b->length;
	}

	/**
	 * Return the enqueued value for a given attribute, if one exists.
	 *
	 * Enqueued updates can take different data types:
	 *  - If an update is enqueued and is boolean, the return will be `true`
	 *  - If an update is otherwise enqueued, the return will be the string value of that update.
	 *  - If an attribute is enqueued to be removed, the return will be `null` to indicate that.
	 *  - If no updates are enqueued, the return will be `false` to differentiate from "removed."
	 *
	 * @since WP_VERSION
	 *
	 * @param string $comparable_name The attribute name in its comparable form.
	 * @return string|boolean|null Value of enqueued update if present, otherwise false.
	 */
	private function get_enqueued_attribute_value( $comparable_name ) {
		if ( self::STATE_MATCHED_TAG !== $this->parser_state ) {
			return false;
		}

		if ( ! isset( $this->lexical_updates[ $comparable_name ] ) ) {
			return false;
		}

		$enqueued_text = $this->lexical_updates[ $comparable_name ]->text;

		// Removed attributes erase the entire span.
		if ( '' === $enqueued_text ) {
			return null;
		}

		/*
		 * Boolean attribute updates are just the attribute name without a corresponding value.
		 *
		 * This value might differ from the given comparable name in that there could be leading
		 * or trailing whitespace, and that the casing follows the name given in `set_attribute`.
		 *
		 * Example:
		 *
		 *     $p->set_attribute( 'data-TEST-id', 'update' );
		 *     'update' === $p->get_enqueued_attribute_value( 'data-test-id' );
		 *
		 * Detect this difference based on the absence of the `=`, which _must_ exist in any
		 * attribute containing a value, e.g. `<input type="text" enabled />`.
		 *                                            ¹           ²
		 *                                       1. Attribute with a string value.
		 *                                       2. Boolean attribute whose value is `true`.
		 */
		$equals_at = strpos( $enqueued_text, '=' );
		if ( false === $equals_at ) {
			return true;
		}

		/*
		 * Finally, a normal update's value will appear after the `=` and
		 * be double-quoted, as performed incidentally by `set_attribute`.
		 *
		 * e.g. `type="text"`
		 *           ¹²    ³
		 *        1. Equals is here.
		 *        2. Double-quoting starts one after the equals sign.
		 *        3. Double-quoting ends at the last character in the update.
		 */
		$enqueued_value = substr( $enqueued_text, $equals_at + 2, -1 );
		/*
		 * We're deliberately not decoding entities in attribute values:
		 *
		 *     Attribute values must not contain direct or indirect entity references to external entities.
		 *
		 * See https://www.w3.org/TR/xml/#sec-starttags.
		 */
		return $enqueued_value;
	}

	/**
	 * Returns the value of a requested attribute from a matched tag opener if that attribute exists.
	 *
	 * Example:
	 *
	 *     $p = new WP_XML_Tag_Processor( '<div enabled class="test" data-test-id="14">Test</div>' );
	 *     $p->next_tag( array( 'class_name' => 'test' ) ) === true;
	 *     $p->get_attribute( 'data-test-id' ) === '14';
	 *     $p->get_attribute( 'enabled' ) === true;
	 *     $p->get_attribute( 'aria-label' ) === null;
	 *
	 *     $p->next_tag() === false;
	 *     $p->get_attribute( 'class' ) === null;
	 *
	 * @since WP_VERSION
	 *
	 * @param string $name Name of attribute whose value is requested.
	 * @return string|true|null Value of attribute or `null` if not available. Boolean attributes return `true`.
	 */
	public function get_attribute( $name ) {
		if (
			self::STATE_MATCHED_TAG !== $this->parser_state &&
			self::STATE_XML_DECLARATION !== $this->parser_state
		) {
			return null;
		}

		// Return any enqueued attribute value updates if they exist.
		$enqueued_value = $this->get_enqueued_attribute_value( $name );
		if ( false !== $enqueued_value ) {
			return $enqueued_value;
		}

		if ( ! isset( $this->attributes[ $name ] ) ) {
			return null;
		}

		$attribute = $this->attributes[ $name ];

		/*
		 * This flag distinguishes an attribute with no value
		 * from an attribute with an empty string value. For
		 * unquoted attributes this could look very similar.
		 * It refers to whether an `=` follows the name.
		 *
		 * e.g. <div boolean-attribute empty-attribute=></div>
		 *           ¹                 ²
		 *        1. Attribute `boolean-attribute` is `true`.
		 *        2. Attribute `empty-attribute` is `""`.
		 */
		if ( true === $attribute->is_true ) {
			return true;
		}

		$raw_value = substr( $this->xml, $attribute->value_starts_at, $attribute->value_length );

		return WP_HTML_Decoder::decode_attribute( $raw_value );
	}

	/**
	 * Gets names of all attributes matching a given prefix in the current tag.
	 *
	 * Note that matching is case-sensitive. This is in accordance with the spec.
	 *
	 * Example:
	 *
	 *     $p = new WP_XML_Tag_Processor( '<div data-ENABLED="1" class="test" DATA-test-id="14">Test</div>' );
	 *     $p->next_tag( array( 'class_name' => 'test' ) ) === true;
	 *     $p->get_attribute_names_with_prefix( 'data-' ) === array( 'data-ENABLED' );
	 *     $p->get_attribute_names_with_prefix( 'DATA-' ) === array( 'DATA-test-id' );
	 *     $p->get_attribute_names_with_prefix( 'DAta-' ) === array();
	 *
	 * @since WP_VERSION
	 *
	 * @param string $prefix Prefix of requested attribute names.
	 * @return array|null List of attribute names, or `null` when no tag opener is matched.
	 */
	public function get_attribute_names_with_prefix( $prefix ) {
		if (
			self::STATE_MATCHED_TAG !== $this->parser_state ||
			$this->is_closing_tag
		) {
			return null;
		}

		$matches = array();
		foreach ( array_keys( $this->attributes ) as $attr_name ) {
			if ( str_starts_with( $attr_name, $prefix ) ) {
				$matches[] = $attr_name;
			}
		}
		return $matches;
	}

	/**
	 * Returns the uppercase name of the matched tag.
	 *
	 * Example:
	 *
	 *     $p = new WP_XML_Tag_Processor( '<div class="test">Test</div>' );
	 *     $p->next_tag() === true;
	 *     $p->get_tag() === 'DIV';
	 *
	 *     $p->next_tag() === false;
	 *     $p->get_tag() === null;
	 *
	 * @since WP_VERSION
	 *
	 * @return string|null Name of currently matched tag in input XML, or `null` if none found.
	 */
	public function get_tag() {
		if ( null === $this->tag_name_starts_at ) {
			return null;
		}

		$tag_name = substr( $this->xml, $this->tag_name_starts_at, $this->tag_name_length );

		if ( self::STATE_MATCHED_TAG === $this->parser_state ) {
			return $tag_name;
		}

		if (
			self::STATE_COMMENT === $this->parser_state &&
			self::PI_NODE === $this->get_comment_type()
		) {
			return $tag_name;
		}

		return null;
	}

	/**
	 * Indicates if the currently matched tag is an empty element tag.
	 *
	 * XML tags ending with a solidus ("/") are parsed as empty elements. They have no
	 * content and no matching closer is expected.

	 * @since WP_VERSION
	 *
	 * @return bool Whether the currently matched tag is an empty element tag.
	 */
	public function is_empty_element_tag() {
		if ( self::STATE_MATCHED_TAG !== $this->parser_state ) {
			return false;
		}

		/*
		 * An empty element tag is defined by the solidus at the _end_ of the tag, not the beginning.
		 *
		 * Example:
		 *
		 *     <figure />
		 *             ^ this appears one character before the end of the closing ">".
		 */
		return '/' === $this->xml[ $this->token_starts_at + $this->token_length - 2 ];
	}

	/**
	 * Indicates if the current tag token is a tag closer.
	 *
	 * Example:
	 *
	 *     $p = new WP_XML_Tag_Processor( '<div></div>' );
	 *     $p->next_tag( array( 'tag_name' => 'div', 'tag_closers' => 'visit' ) );
	 *     $p->is_tag_closer() === false;
	 *
	 *     $p->next_tag( array( 'tag_name' => 'div', 'tag_closers' => 'visit' ) );
	 *     $p->is_tag_closer() === true;
	 *
	 * @since WP_VERSION
	 *
	 * @return bool Whether the current tag is a tag closer.
	 */
	public function is_tag_closer() {
		return (
			self::STATE_MATCHED_TAG === $this->parser_state &&
			$this->is_closing_tag
		);
	}

	/**
	 * Indicates the kind of matched token, if any.
	 *
	 * This differs from `get_token_name()` in that it always
	 * returns a static string indicating the type, whereas
	 * `get_token_name()` may return values derived from the
	 * token itself, such as a tag name or processing
	 * instruction tag.
	 *
	 * Possible values:
	 *  - `#tag` when matched on a tag.
	 *  - `#text` when matched on a text node.
	 *  - `#cdata-section` when matched on a CDATA node.
	 *  - `#comment` when matched on a comment.
	 *  - `#doctype` when matched on a DOCTYPE declaration.
	 *  - `#presumptuous-tag` when matched on an empty tag closer.
	 *
	 * @since WP_VERSION
	 *
	 * @return string|null What kind of token is matched, or null.
	 */
	public function get_token_type() {
		switch ( $this->parser_state ) {
			case self::STATE_MATCHED_TAG:
				return '#tag';

			default:
				return $this->get_token_name();
		}
	}

	/**
	 * Returns the node name represented by the token.
	 *
	 * This matches the DOM API value `nodeName`. Some values
	 * are static, such as `#text` for a text node, while others
	 * are dynamically generated from the token itself.
	 *
	 * Dynamic names:
	 *  - Uppercase tag name for tag matches.
	 *  - `xml` for DOCTYPE declarations.
	 *
	 * Note that if the Tag Processor is not matched on a token
	 * then this function will return `null`, either because it
	 * hasn't yet found a token or because it reached the end
	 * of the document without matching a token.
	 *
	 * @since WP_VERSION
	 *
	 * @return string|null Name of the matched token.
	 */
	public function get_token_name() {
		switch ( $this->parser_state ) {
			case self::STATE_MATCHED_TAG:
				return $this->get_tag();

			case self::STATE_TEXT_NODE:
				return '#text';

			case self::STATE_CDATA_NODE:
				return '#cdata-section';

			case self::STATE_XML_DECLARATION:
				return '#xml-declaration';

			case self::STATE_PI_NODE:
				return '#processing-instructions';

			case self::STATE_COMMENT:
				return '#comment';

			case self::STATE_DOCTYPE:
				return 'xml';

			case self::STATE_PRESUMPTUOUS_TAG:
				return '#presumptuous-tag';
		}
	}

	/**
	 * Indicates what kind of comment produced the comment node.
	 *
	 * Because there are different kinds of XML syntax which produce
	 * comments, the Tag Processor tracks and exposes this as a type
	 * for the comment. Nominally only regular XML comments exist as
	 * they are commonly known, but a number of unrelated syntax errors
	 * also produce comments.
	 *
	 * @see self::COMMENT_AS_ABRUPTLY_CLOSED_COMMENT
	 * @see self::COMMENT_AS_CDATA_LOOKALIKE
	 * @see self::COMMENT_AS_INVALID_XML
	 * @see self::COMMENT_AS_XML_COMMENT
	 * @see self::PI_NODE
	 *
	 * @since WP_VERSION
	 *
	 * @return string|null
	 */
	public function get_comment_type() {
		if ( self::STATE_COMMENT !== $this->parser_state ) {
			return null;
		}

		return $this->comment_type;
	}

	/**
	 * Returns the modifiable text for a matched token, or an empty string.
	 *
	 * Modifiable text is text content that may be read and changed without
	 * changing the XML structure of the document around it. This includes
	 * the contents of `#text` nodes in the XML as well as the inner
	 * contents of XML comments, Processing Instructions, and others, even
	 * though these nodes aren't part of a parsed DOM tree. They also contain
	 * the contents of SCRIPT and STYLE tags, of TEXTAREA tags, and of any
	 * other section in an XML document which cannot contain XML markup (DATA).
	 *
	 * If a token has no modifiable text then an empty string is returned to
	 * avoid needless crashing or type errors. An empty string does not mean
	 * that a token has modifiable text, and a token with modifiable text may
	 * have an empty string (e.g. a comment with no contents).
	 *
	 * @since WP_VERSION
	 *
	 * @return string
	 */
	public function get_modifiable_text() {
		if ( null === $this->text_starts_at ) {
			return '';
		}

		$text = substr( $this->xml, $this->text_starts_at, $this->text_length );

		// Comment data is not decoded.
		if (
			self::STATE_CDATA_NODE === $this->parser_state ||
			self::STATE_COMMENT === $this->parser_state ||
			self::STATE_DOCTYPE === $this->parser_state
		) {
			return $text;
		}

		return WP_HTML_Decoder::decode_text_node( $text );
	}

	/**
	 * Updates or creates a new attribute on the currently matched tag with the passed value.
	 *
	 * For boolean attributes special handling is provided:
	 *  - When `true` is passed as the value, then only the attribute name is added to the tag.
	 *  - When `false` is passed, the attribute gets removed if it existed before.
	 *
	 * For string attributes, the value is escaped using the `esc_attr` function.
	 *
	 * @since WP_VERSION
	 * @since 6.2.1 Fix: Only create a single update for multiple calls with case-variant attribute names.
	 *
	 * @param string      $name  The attribute name to target.
	 * @param string|bool $value The new attribute value.
	 * @return bool Whether an attribute value was set.
	 */
	public function set_attribute( $name, $value ) {
		if(!is_string($value)) {
			_doing_it_wrong(
				__METHOD__,
				__( 'Non-string attribute values cannot be passed to set_attribute().' ),
				'WP_VERSION'
			);
			return false;
		}
		if (
			self::STATE_MATCHED_TAG !== $this->parser_state ||
			$this->is_closing_tag
		) {
			return false;
		}

		/*
		 * WordPress rejects more characters than are strictly forbidden
		 * in XML5. This is to prevent additional security risks deeper
		 * in the WordPress and plugin stack. Specifically the
		 * less-than (<) greater-than (>) and ampersand (&) aren't allowed.
		 *
		 * The use of a PCRE match enables looking for specific Unicode
		 * code points without writing a UTF-8 decoder. Whereas scanning
		 * for one-byte characters is trivial (with `strcspn`), scanning
		 * for the longer byte sequences would be more complicated. Given
		 * that this shouldn't be in the hot path for execution, it's a
		 * reasonable compromise in efficiency without introducing a
		 * noticeable impact on the overall system.
		 *
		 * @see https://xml.spec.whatwg.org/#attributes-2
		 *
		 * @todo As the only regex pattern maybe we should take it out?
		 *       Are Unicode patterns available broadly in Core?
		 */
		if ( preg_match(
			'~[' .
				// Invalid chara
				'"\'>&</ =' .
				// Control characters.
				'\x{00}-\x{1F}' .
				// XML noncharacters.
				'\x{FDD0}-\x{FDEF}' .
				'\x{FFFE}\x{FFFF}\x{1FFFE}\x{1FFFF}\x{2FFFE}\x{2FFFF}\x{3FFFE}\x{3FFFF}' .
				'\x{4FFFE}\x{4FFFF}\x{5FFFE}\x{5FFFF}\x{6FFFE}\x{6FFFF}\x{7FFFE}\x{7FFFF}' .
				'\x{8FFFE}\x{8FFFF}\x{9FFFE}\x{9FFFF}\x{AFFFE}\x{AFFFF}\x{BFFFE}\x{BFFFF}' .
				'\x{CFFFE}\x{CFFFF}\x{DFFFE}\x{DFFFF}\x{EFFFE}\x{EFFFF}\x{FFFFE}\x{FFFFF}' .
				'\x{10FFFE}\x{10FFFF}' .
			']~Ssu',
			$name
		) ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'Invalid attribute name.' ),
				'WP_VERSION'
			);

			return false;
		}

		/*
		 * XML attribute values cannot contain certain characters. However,
		 * even though ampersands are disallowed, entities are legal. 
		 * @TODO: Support entities.
		 */
		if ( strcspn( $value, '<&"' ) !== strlen( $value ) ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'Invalid attribute name.' ),
				'WP_VERSION'
			);

			return false;
		}

		$updated_attribute = "{$name}=\"{$value}\"";

		/*
		 * > An attribute name must not appear more than once
		 * > in the same start-tag or empty-element tag.
		 *     - XML 1.0 spec
		 *
		 * @see https://www.w3.org/TR/xml/#sec-starttags
		 */
		if ( isset( $this->attributes[ $name ] ) ) {
			/*
			 * Update an existing attribute.
			 *
			 * Example – set attribute id to "new" in <div id="initial_id" />:
			 *
			 *     <div id="initial_id"/>
			 *          ^-------------^
			 *          start         end
			 *     replacement: `id="new"`
			 *
			 *     Result: <div id="new"/>
			 */
			$existing_attribute             = $this->attributes[ $name ];
			$this->lexical_updates[ $name ] = new WP_HTML_Text_Replacement(
				$existing_attribute->start,
				$existing_attribute->length,
				$updated_attribute
			);
		} else {
			/*
			 * Create a new attribute at the tag's name end.
			 *
			 * Example – add attribute id="new" to <div />:
			 *
			 *     <div/>
			 *         ^
			 *         start and end
			 *     replacement: ` id="new"`
			 *
			 *     Result: <div id="new"/>
			 */
			$this->lexical_updates[ $name ] = new WP_HTML_Text_Replacement(
				$this->tag_name_starts_at + $this->tag_name_length,
				0,
				' ' . $updated_attribute
			);
		}

		return true;
	}

	/**
	 * Remove an attribute from the currently-matched tag.
	 *
	 * @since WP_VERSION
	 *
	 * @param string $name The attribute name to remove.
	 * @return bool Whether an attribute was removed.
	 */
	public function remove_attribute( $name ) {
		if (
			self::STATE_MATCHED_TAG !== $this->parser_state ||
			$this->is_closing_tag
		) {
			return false;
		}

		/*
		 * If updating an attribute that didn't exist in the input
		 * document, then remove the enqueued update and move on.
		 *
		 * For example, this might occur when calling `remove_attribute()`
		 * after calling `set_attribute()` for the same attribute
		 * and when that attribute wasn't originally present.
		 */
		if ( ! isset( $this->attributes[ $name ] ) ) {
			if ( isset( $this->lexical_updates[ $name ] ) ) {
				unset( $this->lexical_updates[ $name ] );
			}
			return false;
		}

		/*
		 * Removes an existing tag attribute.
		 *
		 * Example – remove the attribute id from <div id="main"/>:
		 *    <div id="initial_id"/>
		 *         ^-------------^
		 *         start         end
		 *    replacement: ``
		 *
		 *    Result: <div />
		 */
		$this->lexical_updates[ $name ] = new WP_HTML_Text_Replacement(
			$this->attributes[ $name ]->start,
			$this->attributes[ $name ]->length,
			''
		);

		return true;
	}

	/**
	 * Returns the string representation of the XML Tag Processor.
	 *
	 * @since WP_VERSION
	 *
	 * @see WP_XML_Tag_Processor::get_updated_xml()
	 *
	 * @return string The processed XML.
	 */
	public function __toString() {
		return $this->get_updated_xml();
	}

	/**
	 * Returns the string representation of the XML Tag Processor.
	 *
	 * @since WP_VERSION
	 * @since 6.2.1 Shifts the internal cursor corresponding to the applied updates.
	 * @since 6.4.0 No longer calls subclass method `next_tag()` after updating XML.
	 *
	 * @return string The processed XML.
	 */
	public function get_updated_xml() {
		$requires_no_updating = 0 === count( $this->lexical_updates );

		/*
		 * When there is nothing more to update and nothing has already been
		 * updated, return the original document and avoid a string copy.
		 */
		if ( $requires_no_updating ) {
			return $this->xml;
		}

		/*
		 * Keep track of the position right before the current tag. This will
		 * be necessary for reparsing the current tag after updating the XML.
		 */
		$before_current_tag = $this->token_starts_at;

		/*
		 * 1. Apply the enqueued edits and update all the pointers to reflect those changes.
		 */
		$before_current_tag += $this->apply_attributes_updates( $before_current_tag );

		/*
		 * 2. Rewind to before the current tag and reparse to get updated attributes.
		 *
		 * At this point the internal cursor points to the end of the tag name.
		 * Rewind before the tag name starts so that it's as if the cursor didn't
		 * move; a call to `next_tag()` will reparse the recently-updated attributes
		 * and additional calls to modify the attributes will apply at this same
		 * location, but in order to avoid issues with subclasses that might add
		 * behaviors to `next_tag()`, the internal methods should be called here
		 * instead.
		 *
		 * It's important to note that in this specific place there will be no change
		 * because the processor was already at a tag when this was called and it's
		 * rewinding only to the beginning of this very tag before reprocessing it
		 * and its attributes.
		 *
		 * <p>Previous XML<em>More XML</em></p>
		 *                 ↑  │ back up by the length of the tag name plus the opening <
		 *                 └←─┘ back up by strlen("em") + 1 ==> 3
		 */
		$this->bytes_already_parsed = $before_current_tag;
		$this->base_class_next_token();

		return $this->xml;
	}

	/**
	 * Parses tag query input into internal search criteria.
	 *
	 * @since WP_VERSION
	 *
	 * @param array|string|null $query {
	 *     Optional. Which tag name to find, having which class, etc. Default is to find any tag.
	 *
	 *     @type string|null $tag_name     Which tag to find, or `null` for "any tag."
	 *     @type int|null    $match_offset Find the Nth tag matching all search criteria.
	 *                                     1 for "first" tag, 3 for "third," etc.
	 *                                     Defaults to first tag.
	 *     @type string      $tag_closers  "visit" or "skip": whether to stop on tag closers, e.g. </div>.
	 * }
	 */
	private function parse_query( $query ) {
		if ( null !== $query && $query === $this->last_query ) {
			return;
		}

		$this->last_query          = $query;
		$this->sought_tag_name     = null;
		$this->sought_match_offset = 1;
		$this->stop_on_tag_closers = false;

		// A single string value means "find the tag of this name".
		if ( is_string( $query ) ) {
			$this->sought_tag_name = $query;
			return;
		}

		// An empty query parameter applies no restrictions on the search.
		if ( null === $query ) {
			return;
		}

		// If not using the string interface, an associative array is required.
		if ( ! is_array( $query ) ) {
			_doing_it_wrong(
				__METHOD__,
				__( 'The query argument must be an array or a tag name.' ),
				'WP_VERSION'
			);
			return;
		}

		if ( isset( $query['tag_name'] ) && is_string( $query['tag_name'] ) ) {
			$this->sought_tag_name = $query['tag_name'];
		}

		if ( isset( $query['match_offset'] ) && is_int( $query['match_offset'] ) && 0 < $query['match_offset'] ) {
			$this->sought_match_offset = $query['match_offset'];
		}

		if ( isset( $query['tag_closers'] ) ) {
			$this->stop_on_tag_closers = 'visit' === $query['tag_closers'];
		}
	}


	/**
	 * Checks whether a given tag and its attributes match the search criteria.
	 *
	 * @since WP_VERSION
	 *
	 * @return bool Whether the given tag and its attribute match the search criteria.
	 */
	private function matches() {
		if ( $this->is_closing_tag && ! $this->stop_on_tag_closers ) {
			return false;
		}

		// Does the tag name match the requested tag name in a case-insensitive manner?
		if ( null !== $this->sought_tag_name ) {
			/*
			 * String (byte) length lookup is fast. If they aren't the
			 * same length then they can't be the same string values.
			 */
			if ( strlen( $this->sought_tag_name ) !== $this->tag_name_length ) {
				return false;
			}

			/*
			 * Check each character to determine if they are the same.
			 */
			for ( $i = 0; $i < $this->tag_name_length; $i++ ) {
				if ( $this->xml[ $this->tag_name_starts_at + $i ] !== $this->sought_tag_name[ $i ] ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Parser Ready State.
	 *
	 * Indicates that the parser is ready to run and waiting for a state transition.
	 * It may not have started yet, or it may have just finished parsing a token and
	 * is ready to find the next one.
	 *
	 * @since WP_VERSION
	 *
	 * @access private
	 */
	const STATE_READY = 'STATE_READY';

	/**
	 * Parser Complete State.
	 *
	 * Indicates that the parser has reached the end of the document and there is
	 * nothing left to scan. It finished parsing the last token completely.
	 *
	 * @since WP_VERSION
	 *
	 * @access private
	 */
	const STATE_COMPLETE = 'STATE_COMPLETE';

	/**
	 * Parser Incomplete Input State.
	 *
	 * Indicates that the parser has reached the end of the document before finishing
	 * a token. It started parsing a token but there is a possibility that the input
	 * XML document was truncated in the middle of a token.
	 *
	 * The parser is reset at the start of the incomplete token and has paused. There
	 * is nothing more than can be scanned unless provided a more complete document.
	 *
	 * @since WP_VERSION
	 *
	 * @access private
	 */
	const STATE_INCOMPLETE_INPUT = 'STATE_INCOMPLETE_INPUT';

	/**
	 * Parser Invalid Input State.
	 *
	 * Indicates that the parsed xml document contains malformed input and cannot be parsed.
	 *
	 * @since WP_VERSION
	 *
	 * @access private
	 */
	const STATE_INVALID_INPUT = 'STATE_INVALID_INPUT';

	/**
	 * Parser Matched Tag State.
	 *
	 * Indicates that the parser has found an XML tag and it's possible to get
	 * the tag name and read or modify its attributes (if it's not a closing tag).
	 *
	 * @since WP_VERSION
	 *
	 * @access private
	 */
	const STATE_MATCHED_TAG = 'STATE_MATCHED_TAG';

	/**
	 * Parser Text Node State.
	 *
	 * Indicates that the parser has found a text node and it's possible
	 * to read and modify that text.
	 *
	 * @since WP_VERSION
	 *
	 * @access private
	 */
	const STATE_TEXT_NODE = 'STATE_TEXT_NODE';

	/**
	 * Parser CDATA Node State.
	 *
	 * Indicates that the parser has found a CDATA node and it's possible
	 * to read and modify its modifiable text. Note that in XML there are
	 * no CDATA nodes outside of foreign content (SVG and MathML). Outside
	 * of foreign content, they are treated as XML comments.
	 *
	 * @since WP_VERSION
	 *
	 * @access private
	 */
	const STATE_CDATA_NODE = 'STATE_CDATA_NODE';

	/**
	 * Indicates that the parser has found an XML processing instruction.
	 *
	 * @since WP_VERSION
	 *
	 * @access private
	 */
	const STATE_PI_NODE = 'STATE_PI_NODE';

	/**
	 * Indicates that the parser has found an XML declaration
	 *
	 * @since WP_VERSION
	 *
	 * @access private
	 */
	const STATE_XML_DECLARATION = 'STATE_XML_DECLARATION';

	/**
	 * Indicates that the parser has found an XML comment and it's
	 * possible to read and modify its modifiable text.
	 *
	 * @since WP_VERSION
	 *
	 * @access private
	 */
	const STATE_COMMENT = 'STATE_COMMENT';

	/**
	 * Indicates that the parser has found a DOCTYPE node and it's
	 * possible to read and modify its modifiable text.
	 *
	 * @since WP_VERSION
	 *
	 * @access private
	 */
	const STATE_DOCTYPE = 'STATE_DOCTYPE';

	/**
	 * Indicates that the parser has found an empty tag closer `</>`.
	 *
	 * Note that in XML there are no empty tag closers, and they
	 * are ignored. Nonetheless, the Tag Processor still
	 * recognizes them as they appear in the XML stream.
	 *
	 * These were historically discussed as a "presumptuous tag
	 * closer," which would close the nearest open tag, but were
	 * dismissed in favor of explicitly-closing tags.
	 *
	 * @since WP_VERSION
	 *
	 * @access private
	 */
	const STATE_PRESUMPTUOUS_TAG = 'STATE_PRESUMPTUOUS_TAG';

	/**
	 * Indicates that a comment was created when encountering abruptly-closed XML comment.
	 *
	 * Example:
	 *
	 *     <!-->
	 *     <!--->
	 *
	 * @since WP_VERSION
	 */
	const COMMENT_AS_ABRUPTLY_CLOSED_COMMENT = 'COMMENT_AS_ABRUPTLY_CLOSED_COMMENT';

	/**
	 * Indicates that a comment was created when encountering
	 * normative XML comment syntax.
	 *
	 * Example:
	 *
	 *     <!-- this is a comment -->
	 *
	 * @since WP_VERSION
	 */
	const COMMENT_AS_XML_COMMENT = 'COMMENT_AS_XML_COMMENT';

	/**
	 * Indicates that a comment would be parsed as a Processing
	 * Instruction node, were they to exist within XML.
	 *
	 * Example:
	 *
	 *     <?wp __( 'Like' ) ?>
	 *
	 * This is an XML comment, but it looks like a CDATA node.
	 *
	 * @since WP_VERSION
	 */
	const PI_NODE = 'PI_NODE';
}